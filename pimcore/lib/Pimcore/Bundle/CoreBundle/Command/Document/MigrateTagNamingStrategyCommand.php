<?php

declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Bundle\CoreBundle\Command\Document;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Statement;
use Pimcore\Bundle\AdminBundle\Session\Handler\SimpleAdminSessionHandler;
use Pimcore\Cache;
use Pimcore\Config;
use Pimcore\Console\AbstractCommand;
use Pimcore\Console\Application;
use Pimcore\Console\Traits\DryRun;
use Pimcore\Document\Tag\NamingStrategy\Migration\MigrationListener;
use Pimcore\Document\Tag\NamingStrategy\NamingStrategyInterface;
use Pimcore\Document\Tag\NamingStrategy\NestedNamingStrategy;
use Pimcore\Model\Document;
use Pimcore\Model\Object\AbstractObject;
use Pimcore\Model\Object\Localizedfield;
use Pimcore\Model\User;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class MigrateTagNamingStrategyCommand extends AbstractCommand
{
    use DryRun;

    /**
     * @var array
     */
    private $validDocumentTypes = [
        'page',
        'snippet',
        'email',
        'printpage'
    ];

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this
            ->setName('pimcore:documents:migrate-naming-strategy')
            ->addOption(
                'strategy', 's',
                InputOption::VALUE_REQUIRED,
                'The naming strategy to use',
                NestedNamingStrategy::STRATEGY_NAME
            )
            ->addOption(
                'document', 'd',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Document IDs to process. If none are given, all documents will be processed'
            )
            ->addOption(
                'ignore', 'D',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Document IDs to ignore.'
            )
            ->addOption(
                'user', 'u',
                InputOption::VALUE_REQUIRED,
                'Run command under given user name',
                'admin'
            );

        $this->configureDryRunOption('Do not update editables. Just render documents and gather name mapping');
    }

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);

        // initialize admin mode
        Cache::disable();
        \Pimcore::setAdminMode();
        Document::setHideUnpublished(false);
        AbstractObject::setHideUnpublished(false);
        AbstractObject::setGetInheritedValues(false);
        Localizedfield::setGetFallbackValues(false);

        $this->initializeSession();
    }

    /**
     * Sets admin session to a mock array session to make sure any session related functionality works
     */
    private function initializeSession()
    {
        $session = new Session(new MockArraySessionStorage());

        $configurator = $this->getContainer()->get('pimcore_admin.session.configurator.admin_session_bags');
        $configurator->configure($session);

        $handler = new SimpleAdminSessionHandler($session);

        \Pimcore\Tool\Session::setHandler($handler);
    }

    /**
     * Initializes given user and checks if the user is admin
     *
     * @param InputInterface $input
     */
    private function initializeUser(InputInterface $input)
    {
        $username = $input->getOption('user');

        /** @var User $user */
        $user = User::getByName($username);

        if (!$user) {
            throw new \InvalidArgumentException(sprintf('User "%s" could not be loaded'));
        }

        if (!$user->isAdmin()) {
            throw new \InvalidArgumentException(sprintf('User "%s" does not have admin rights'));
        }

        // See ElementListener. The UserLoader will be used to fetch the admin user when rendering
        // documents to make sure unpublished documents can't be seen by non-admin requests. By setting
        // the user on the loader, no session lookup will be done and this user will be used instead.
        $loader = $this->getContainer()->get('pimcore_admin.security.user_loader');
        $loader->setUser($user);
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->initializeUser($input);
        } catch (\InvalidArgumentException $e) {
            $this->io->error($e->getMessage());

            return 1;
        }

        $systemConfig = Config::getSystemConfig()->toArray();
        $mainDomain   = $systemConfig['general']['domain'];

        if (!$mainDomain) {
            $this->io->error('No domain set in "Settings" -> "System" -> "Website" -> "Domain". Please set a domain before proceeding.');

            return 2;
        }

        // register migration listener with new naming strategy
        $strategy   = $this->getNamingStrategy($input);
        $subscriber = new MigrationListener($strategy);

        $dispatcher = $this->getContainer()->get('event_dispatcher');
        $dispatcher->addSubscriber($subscriber);

        /** @var Application $app */
        $app    = $this->getApplication();
        $kernel = $app->getKernel();

        $documentIds     = $this->getDocumentIds($input);
        $renderingErrors = [];

        $this->io->title('[STEP 1] Rendering all documents to gather new element mapping');

        foreach ($this->getDocuments($documentIds) as $document) {
            try {
                $this->renderDocument($kernel, $document, $mainDomain);
            } catch (\Exception $e) {
                $renderingErrors[$document->getId()] = [
                    'documentId'   => $document->getId(),
                    'documentPath' => $document->getRealFullPath(),
                    'exception'    => $e,
                ];

                $this->io->error($e->getMessage());
            }
        }

        if (count($renderingErrors) === 0) {
            $this->io->success('All documents were rendered successfully, now proceeding to update names based on the gathered mapping');
        } else {
            $this->io->warning('Not all documents could be rendered.');

            if (!$this->confirmProceedAfterRenderingErrors($renderingErrors)) {
                return 3;
            }
        }

        $nameMapping = $subscriber->getNameMapping();

        // do not migrate any element in errored documents
        foreach (array_keys($renderingErrors) as $documentId) {
            //
            if (isset($nameMapping[$documentId])) {
                unset($nameMapping[$documentId]);
            }
        }

        if (empty($nameMapping)) {
            $this->io->writeln('');
            $this->io->success(sprintf(
                'Nothing to migrate.',
                $strategy->getName()
            ));

            return 0;
        }

        $this->io->writeln(PHP_EOL . PHP_EOL);
        $this->io->title('[STEP 2] Rename preflight...checking if none of the new tag names already exist in the DB');

        try {
            $this->testRenames($nameMapping);
        } catch (\Exception $e) {
            $this->io->error(sprintf('Rename prerequisites failed. Error: %s', $e->getMessage()));

            return 4;
        }


        $this->io->writeln(PHP_EOL . PHP_EOL);
        $this->io->title('[STEP 3] Renaming editables to their new names');

        try {
            $this->processRenames($nameMapping);
        } catch (\Exception $e) {
            $this->io->writeln('');
            $this->io->error($e->getMessage());
            $this->io->warning('All changes were rolled back. Please fix any problems and try again.');

            return 5;
        }

        $this->io->writeln(PHP_EOL . PHP_EOL);
        $this->io->success(sprintf(
            'Names were successfully migrated!' . PHP_EOL . PHP_EOL . 'Please reconfigure Pimcore now to use the "%s" strategy and clear the cache.',
            $strategy->getName()
        ));
    }

    /**
     * Renders a document by dispatching a new master request for the document URI
     *
     * @param HttpKernelInterface $kernel
     * @param Document $document
     * @param string $mainDomain
     *
     * @throws \Exception
     */
    private function renderDocument(HttpKernelInterface $kernel, Document $document, string $mainDomain)
    {
        $host = $mainDomain;
        $path = $document->getRealFullPath();

        $uri     = sprintf('http://%s%s', $host, $path);
        $request = Request::create($uri, 'GET', [
            'pimcore_preview' => true
        ]);

        $this->writeSimpleSection(sprintf(
            'Rendering document %s with ID <info>%d</info>',
            $document->getRealFullPath(),
            $document->getId()
        ));

        $this->io->writeln(sprintf('URI: <comment>%s</comment>', $request->getUri()));

        ob_start();

        try {
            $kernel->handle($request, HttpKernelInterface::MASTER_REQUEST, false);
        } catch (\Exception $e) {
            $this->io->error($e->getMessage());
            throw $e;
        } finally {
            ob_get_contents();
            ob_end_clean();
        }

        $this->io->writeln('');
    }

    /**
     * Tests if renames can safely be done. Check if old names exist in the DB and if the new name does not.
     *
     * @param array $nameMapping
     */
    private function testRenames(array $nameMapping)
    {
        $db   = $this->getContainer()->get('database_connection');
        $stmt = $db->prepare('SELECT documentId, name FROM documents_elements WHERE documentId = :documentId AND name = :name');

        foreach ($nameMapping as $documentId => $mapping) {
            $this->writeSimpleSection(sprintf('Checking document %d', $documentId));

            foreach ($mapping as $oldName => $newName) {
                $this->io->writeln(sprintf(
                    'Checking rename from <info>%s</info> to <info>%s</info>',
                    $oldName,
                    $newName
                ));

                // check the old editable exists in the DB
                $oldResult = $stmt->execute([
                    'documentId' => $documentId,
                    'name'       => $oldName
                ]);

                if (!$oldResult) {
                    throw new \RuntimeException(sprintf(
                        'Failed to load old editable (document ID: %d, name: %s)',
                        $documentId,
                        $oldName
                    ));
                }

                if (count($stmt->fetchAll()) === 0) {
                    $this->io->writeln(sprintf('<error>%s</error>' . PHP_EOL, sprintf(
                        'Ignoring old editable (document ID: %d, name: %s) as it was not found',
                        $documentId,
                        $oldName
                    )));
                }

                // check if there is no new editable
                $newResult = $stmt->execute([
                    'documentId' => $documentId,
                    'name'       => $newName
                ]);

                if (!$newResult) {
                    throw new \RuntimeException(sprintf(
                        'Failed to query for new editable existence (document ID: %d, name: %s)',
                        $documentId,
                        $newName
                    ));
                }

                if (count($stmt->fetchAll()) !== 0) {
                    throw new \RuntimeException(sprintf(
                        'New editable already exists in the DB (document ID: %d, name: %s)',
                        $documentId,
                        $newName
                    ));
                }
            }

            $this->output->writeln('');
        }
    }

    /**
     * Updates elements with their new names
     *
     * @param array $nameMapping
     *
     * @throws \Exception
     */
    private function processRenames(array $nameMapping)
    {
        $db = $this->getContainer()->get('database_connection');

        /** @var Statement $stmt */
        $stmt = null;
        if (!$this->isDryRun()) {
            $stmt = $db->prepare('UPDATE documents_elements SET name = :newName WHERE documentId = :documentId and name = :oldName');
            $db->beginTransaction();
        }

        try {
            foreach ($nameMapping as $documentId => $mapping) {
                $this->writeSimpleSection(sprintf('Processing document %d', $documentId));

                foreach ($mapping as $oldName => $newName) {
                    $message = sprintf(
                        'Renaming editable <info>%s</info> to <info>%s</info>',
                        $oldName,
                        $newName
                    );

                    if ($this->isDryRun()) {
                        $message = $this->prefixDryRun($message, '[DRY-RUN]');
                    } else {
                        $message = '  <comment>*</comment> ' . $message;
                    }

                    $this->io->writeln($message);

                    if ($this->isDryRun()) {
                        continue;
                    }

                    $result = $stmt->execute([
                        'documentId' => $documentId,
                        'oldName'    => $oldName,
                        'newName'    => $newName,
                    ]);

                    if (!$result) {
                        throw new \RuntimeException(sprintf(
                            'Failed to update name from %s to %s for document %d',
                            $oldName,
                            $newName,
                            $documentId
                        ));
                    }
                }
            }

            if (!$this->isDryRun()) {
                $db->commit();
            }
        } catch (\Exception $e) {
            if (!$this->isDryRun()) {
                $db->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Loads given naming strategy and checks if it is not the same as the currently configured one
     *
     * @param InputInterface $input
     *
     * @return NamingStrategyInterface
     */
    private function getNamingStrategy(InputInterface $input): NamingStrategyInterface
    {
        $container = $this->getContainer();

        $strategyName = $input->getOption('strategy');
        $strategyId   = 'pimcore.document.tag.naming.strategy.' . $strategyName;
        if (!$container->has($strategyId)) {
            throw new \InvalidArgumentException(sprintf('The naming strategy "%s" does not exist', $strategyName));
        }

        /** @var NamingStrategyInterface $strategy */
        $strategy           = $container->get($strategyId);
        $configuredStrategy = $container->get('pimcore.document.tag.naming.strategy');

        if ($strategy === $configuredStrategy) {
            throw new \LogicException(sprintf('The strategy "%s" is already configured. You can\'t migrate to the same strategy as the configured one.'));
        }

        return $strategy;
    }

    /**
     * Gets document IDs and filters ignored ones
     *
     * @param InputInterface $input
     *
     * @return array
     */
    private function getDocumentIds(InputInterface $input): array
    {
        $documentIds = $input->getOption('document');
        if (empty($documentIds)) {
            // load all documents if no IDs were passed as option
            $documentIds = $this->getAllDocumentIds();
        }

        $ignoredIds = $input->getOption('ignore');
        if (!empty($ignoredIds)) {
            $documentIds = array_filter($documentIds, function ($id) use ($ignoredIds) {
                return !in_array($id, $ignoredIds);
            });
        }

        return $documentIds;
    }

    /**
     * Returns all document IDs for documents matching valid types
     *
     * @return array
     */
    private function getAllDocumentIds(): array
    {
        $db = $this->getContainer()->get('database_connection');
        $qb = $db->createQueryBuilder();
        $qb
            ->select('id')
            ->from('documents')
            ->where('type IN (:validTypes)');

        $qb->setParameter('validTypes', $this->validDocumentTypes, Connection::PARAM_STR_ARRAY);

        $stmt   = $qb->execute();
        $result = $stmt->fetchAll();

        $documentIds = array_map(function ($id) {
            $id = (int)$id;

            if ($id <= 0) {
                throw new \RuntimeException(sprintf('Invalid ID: %d', $id));
            }

            return $id;
        }, array_column($result, 'id'));

        return $documentIds;
    }

    /**
     * Loads documents for configured document IDs
     *
     * @param int[] $documentIds
     *
     * @return \Generator|Document[]
     */
    private function getDocuments(array $documentIds): \Generator
    {
        foreach ($documentIds as $documentId) {
            $document = Document::getById($documentId);

            if (!$document || !$document instanceof Document\PageSnippet) {
                throw new \InvalidArgumentException(sprintf('Invalid document: %d', $documentId));
            }

            if (!in_array($document->getType(), $this->validDocumentTypes)) {
                throw new \InvalidArgumentException(sprintf(
                    'Document "%s" ("%d") has no valid type',
                    $document->getRealFullPath(),
                    $document->getId()
                ));
            }

            yield $document;
        }
    }

    private function confirmProceedAfterRenderingErrors(array $errors): bool
    {
        $messages = [];
        foreach ($errors as $documentId => $error) {
            $messages[] = sprintf(
                '<comment>%s</comment> (ID <info>%d</info>): %s',
                $error['documentPath'],
                $documentId,
                $error['exception']->getMessage()
            );
        }

        $this->io->writeln('The following errors were encountered while rendering the selected documents:');
        $this->io->writeln('');

        $this->io->listing($messages);

        $this->io->writeln('');
        $this->io->writeln('<comment>WARNING:</comment> You can proceed the migration for all other documents, but your unmigrated documents will potentially lose their data. It\'s strongly advised to fix any rendering issues before proceeding');

        $helper   = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            'Proceed the migration for successfully rendered documents?  (y/n) ',
            false
        );

        return $helper->ask($this->io->getInput(), $this->io->getOutput(), $question);
    }

    private function writeSimpleSection(string $message)
    {
        $this->io->writeln([
            '',
            $message,
            str_repeat('-', Helper::strlenWithoutDecoration($this->io->getFormatter(), $message)),
            ''
        ]);
    }
}
