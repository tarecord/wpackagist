<?php

namespace Outlandish\Wpackagist\Controller;

use Doctrine\DBAL\Connection;
use Outlandish\Wpackagist\Service;
use Pagerfanta\Adapter\DoctrineDbalSingleTableAdapter;
use Pagerfanta\Pagerfanta;
use PDO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

class MainController extends AbstractController
{
    /** @var FormFactoryInterface */
    private $formFactory;
    /** @var FormInterface */
    private $form;

    public function __construct(FormFactoryInterface $formFactory)
    {
        $this->formFactory = $formFactory;
    }

    /**
     * @Route("packages.json", name="json_index")
     */
    public function packageIndexJson(): Response
    {
        $response = new Response(
            file_get_contents($_SERVER['PACKAGE_PATH'] . '/packages.json')
        );
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * @Route("p/{file}.json", name="json_provider")
     * @Route("p/{dir}/{file}.json", name="json_package")
     * @param ?string $dir   Directory: wpackagist-plugin or wpackagist-theme.
     * @param string $file  Filename excluding '.json'.
     * @return Response
     */
    public function packageJson(string $file, ?string $dir = null): Response
    {
        $dir = str_replace('.', '', $dir);
        $file = str_replace('.', '', $file);

        if (!empty($dir) && !in_array($dir, ['wpackagist-plugin', 'wpackagist-theme'], true)) {
            throw new BadRequestException('Unexpected package path');
        }

        $fullPath = empty($dir)
            ? "{$_SERVER['PACKAGE_PATH']}/p/{$file}.json"
            : "{$_SERVER['PACKAGE_PATH']}/p/$dir/{$file}.json";
        if (!file_exists($fullPath)) {
            throw new NotFoundHttpException();
        }

        $response = new Response(file_get_contents($fullPath));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    public function home(Request $request): Response
    {
        return $this->render('index.twig', [
            'title'      => 'WordPress Packagist: Manage your plugins and themes with Composer',
            'searchForm' => $this->getForm()->handleRequest($request)->createView(),
        ]);
    }

    public function search(Request $request, Connection $connection): Response
    {
        $queryBuilder = $connection->createQueryBuilder();

        $form = $this->getForm();
        $form->handleRequest($request);

        $data = $form->getData();
        $type = $data['type'] ?? null;
        $active = $data['active_only'] ?? false;
        $query = empty($data['q']) ? null : trim($data['q']);

        $data = [
            'title'              => "WordPress Packagist: Search packages",
            'searchForm'         => $form->createView(),
            'currentPageResults' => '',
            'error'              => '',
        ];

        $queryBuilder
            ->select('*')
            ->from('packages', 'p');

        switch ($type) {
            case 'theme':
                $queryBuilder
                    ->andWhere('class_name = :class')
                    ->setParameter(':class', 'Outlandish\Wpackagist\Package\Theme');
                break;
            case 'plugin':
                $queryBuilder
                    ->andWhere('class_name = :class')
                    ->setParameter(':class', 'Outlandish\Wpackagist\Package\Plugin');
                break;
            default:
                break;
        }

        switch ($active) {
            case 1:
                $queryBuilder->andWhere('is_active');
                break;

            default:
                $queryBuilder->orderBy('is_active', 'DESC');
                break;
        }

        if (!empty($query)) {
            $queryBuilder
                ->andWhere('name LIKE :name')
                ->orWhere('display_name LIKE :name')
                ->addOrderBy('name LIKE :order', 'DESC')
                ->addOrderBy('name', 'ASC')
                ->setParameter(':name', "%{$query}%")
                ->setParameter(':order', "{$query}%");
        } else {
            $queryBuilder
                ->addOrderBy('last_committed', 'DESC');
        }

        $countField = 'p.name';
        $adapter    = new DoctrineDbalSingleTableAdapter($queryBuilder, $countField);
        $pagerfanta = new Pagerfanta($adapter);
        $pagerfanta->setMaxPerPage(30);
        $pagerfanta->setCurrentPage($request->query->get('page', 1));

        $data['pager']              = $pagerfanta;
        $data['currentPageResults'] = $pagerfanta->getCurrentPageResults();

        return $this->render('search.twig', $data);
    }

    public function update(Request $request, Connection $connection, Service\Update $updateService): Response
    {
        // first run the update command
        $name = $request->get('name');
        if (!trim($name)) {
            return new Response('Invalid Request',400);
        }

        $query = $connection->prepare('SELECT * FROM packages WHERE name = :name');
        $query->execute([ $name ]);
        $package = $query->fetch(PDO::FETCH_ASSOC);

        if (!$package) {
            return new Response('Not Found',404);
        }
        $safeName = $package['name'];

        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && $_SERVER['HTTP_X_FORWARDED_FOR']) {
            $splitIp = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $userIp  = trim($splitIp[0]);
        } else {
            $userIp = $_SERVER['REMOTE_ADDR'];
        }

        $count = $this->getRequestCountByIp($userIp, $connection);
        if ($count > 10) {
            return new Response('Too many requests. Try again in an hour.', 403);
        }

        $updateService->update(new NullOutput(), $safeName);

        return new RedirectResponse('/search?q=' . $safeName);
    }

    private function getForm(): FormInterface
    {
        if (!$this->form) {
            $this->form = $this->formFactory
                // A named builder with blank name enables not having a param prefix like `formName[fieldName]`.
                ->createNamedBuilder('', FormType::class, null, ['csrf_protection' => false])
                ->setAction('search')
                ->setMethod('GET')
                ->add('q', SearchType::class)
                ->add('type', ChoiceType::class, [
                    'choices' => [
                        'All packages' => 'any',
                        'Plugins' => 'plugin',
                        'Themes' => 'theme',
                    ],
                ])
                ->add('search', SubmitType::class)
                ->getForm();
        }

        return $this->form;
    }

    /**
     * @param string $ip
     * @param Connection $db
     * @return int The number of requests within the past 24 hours
     * @throws \Doctrine\DBAL\DBALException
     * @todo move to a Repository helper?
     */
    private function getRequestCountByIp(string $ip, Connection $db): int
    {
        $query = $db->prepare(
            "SELECT * FROM requests WHERE ip_address = :ip AND last_request > :cutoff"
        );
        $query->execute([
            'cutoff' => (new \DateTime())->sub(new \DateInterval('PT1H'))->format($db->getDatabasePlatform()->getDateTimeFormatString()),
            'ip' => $ip,
        ]);

        $requestHistory = $query->fetch(PDO::FETCH_ASSOC);
        if (!$requestHistory) {
            $this->resetRequestCount($ip, $db);
            return 1;
        }

        $update = $db->prepare(
            'UPDATE requests SET request_count = request_count + 1, last_request = CURRENT_TIMESTAMP WHERE ip_address = :ip'
        );

        $update->execute([ $ip ]);
        return $requestHistory['request_count'] + 1;
    }

    /**
     * Add an entry to the requests table for the provided IP address.
     * Has the side effect of removing all expired entries.
     *
     * @param string $ip
     * @param Connection $db
     * @throws \Doctrine\DBAL\DBALException
     * @todo move to a Repository helper?
     */
    private function resetRequestCount(string $ip, Connection $db)
    {
        $prune = $db->prepare('DELETE FROM requests WHERE last_request < :cutoff');
        $prune->bindValue('cutoff', (new \DateTime())->sub(new \DateInterval('PT1H'))->format($db->getDatabasePlatform()->getDateTimeFormatString()));
        $prune->execute();
        $insert = $db->prepare(
            'INSERT INTO requests (ip_address, last_request, request_count) VALUES (:ip_address, CURRENT_TIMESTAMP, 1)'
        );
        $insert->execute([ $ip ]);
    }
}
