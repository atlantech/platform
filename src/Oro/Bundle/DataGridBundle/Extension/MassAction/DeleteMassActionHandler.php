<?php

namespace Oro\Bundle\DataGridBundle\Extension\MassAction;

use Doctrine\ORM\EntityManager;

use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\TranslatorInterface;

use Oro\Component\MessageQueue\Client\MessageProducerInterface;

use Oro\Bundle\DataGridBundle\Datasource\Orm\IterableResult;
use Oro\Bundle\DataGridBundle\Datasource\ResultRecordInterface;
use Oro\Bundle\DataGridBundle\Exception\LogicException;
use Oro\Bundle\DataGridBundle\Extension\MassAction\Actions\Ajax\MassDelete\MassDeleteLimiter;
use Oro\Bundle\DataGridBundle\Extension\MassAction\Actions\Ajax\MassDelete\MassDeleteLimitResult;
use Oro\Bundle\PlatformBundle\Manager\OptionalListenerManager;
use Oro\Bundle\SearchBundle\Async\Topics;
use Oro\Bundle\SecurityBundle\SecurityFacade;

class DeleteMassActionHandler implements MassActionHandlerInterface
{
    const FLUSH_BATCH_SIZE = 100;

    /** @var RegistryInterface */
    protected $registry;

    /** @var TranslatorInterface */
    protected $translator;

    /** @var SecurityFacade */
    protected $securityFacade;

    /** @var MassDeleteLimiter */
    protected $limiter;

    /** @var RequestStack */
    protected $requestStack;

    /** @var OptionalListenerManager */
    protected $listenerManager;

    /** @var  MessageProducerInterface */
    protected $producer;

    /** @var string */
    protected $responseMessage = 'oro.grid.mass_action.delete.success_message';

    /**
     * @param RegistryInterface        $registry
     * @param TranslatorInterface      $translator
     * @param SecurityFacade           $securityFacade
     * @param MassDeleteLimiter        $limiter
     * @param RequestStack             $requestStack
     * @param OptionalListenerManager  $listenerManager
     * @param MessageProducerInterface $producer
     */
    public function __construct(
        RegistryInterface $registry,
        TranslatorInterface $translator,
        SecurityFacade $securityFacade,
        MassDeleteLimiter $limiter,
        RequestStack $requestStack,
        OptionalListenerManager $listenerManager,
        MessageProducerInterface $producer
    ) {
        $this->registry        = $registry;
        $this->translator      = $translator;
        $this->securityFacade  = $securityFacade;
        $this->limiter         = $limiter;
        $this->requestStack    = $requestStack;
        $this->listenerManager = $listenerManager;
        $this->producer = $producer;
    }

    /**
     * {@inheritDoc}
     */
    public function handle(MassActionHandlerArgs $args)
    {
        $limitResult = $this->limiter->getLimitResult($args);
        $method      = $this->requestStack->getMasterRequest()->getMethod();
        if ($method === 'POST') {
            $result = $this->getPostResponse($limitResult);
        } elseif ($method === 'DELETE') {
            $this->limiter->limitQuery($limitResult, $args);
            $result = $this->doDelete($args);
        } else {
            $result = $this->getNotSupportedResponse($method);
        }

        return $result;
    }

    /**
     * Finish processed batch
     *
     * @param EntityManager $manager
     * @param string        $entityName
     * @param array         $deletedIds
     */
    protected function finishBatch(EntityManager $manager, $entityName, array $deletedIds)
    {
        $body = [];
        foreach ($deletedIds as $deletedId) {
            $body [] = [
                'class' => $entityName,
                'id' => $deletedId
            ];
        }

        $manager->flush();

        $this->producer->send(Topics::INDEX_ENTITIES, $body);

        $manager->clear();
    }

    /**
     * @param MassActionHandlerArgs $args
     * @param int                   $entitiesCount
     *
     * @return MassActionResponse
     */
    protected function getDeleteResponse(MassActionHandlerArgs $args, $entitiesCount = 0)
    {
        $massAction      = $args->getMassAction();
        $responseMessage = $massAction->getOptions()->offsetGetByPath('[messages][success]', $this->responseMessage);

        $successful = $entitiesCount > 0;
        $options    = ['count' => $entitiesCount];

        return new MassActionResponse(
            $successful,
            $this->translator->transChoice(
                $responseMessage,
                $entitiesCount,
                ['%count%' => $entitiesCount]
            ),
            $options
        );
    }

    /**
     * @param MassDeleteLimitResult $limitResult
     *
     * @return MassActionResponse
     */
    protected function getPostResponse(MassDeleteLimitResult $limitResult)
    {
        return new MassActionResponse(
            true,
            'OK',
            [
                'selected'  => $limitResult->getSelected(),
                'deletable' => $limitResult->getDeletable(),
                'max_limit' => $limitResult->getMaxLimit()
            ]
        );
    }

    /**
     * @param $method
     *
     * @return MassActionResponse
     */
    protected function getNotSupportedResponse($method)
    {
        return new MassActionResponse(
            false,
            sprintf('Method "%s" is not supported', $method)
        );
    }

    /**
     * @param MassActionHandlerArgs $args
     *
     * @return string
     * @throws LogicException
     */
    protected function getEntityName(MassActionHandlerArgs $args)
    {
        $massAction = $args->getMassAction();
        $entityName = $massAction->getOptions()->offsetGet('entity_name');
        if (!$entityName) {
            throw new LogicException(sprintf('Mass action "%s" must define entity name', $massAction->getName()));
        }

        return $entityName;
    }

    /**
     * @param MassActionHandlerArgs $args
     *
     * @throws LogicException
     * @return string
     */
    protected function getEntityIdentifierField(MassActionHandlerArgs $args)
    {
        $massAction = $args->getMassAction();
        $identifier = $massAction->getOptions()->offsetGet('data_identifier');
        if (!$identifier) {
            throw new LogicException(sprintf('Mass action "%s" must define identifier name', $massAction->getName()));
        }

        // if we ask identifier that's means that we have plain data in array
        // so we will just use column name without entity alias
        if (strpos('.', $identifier) !== -1) {
            $parts      = explode('.', $identifier);
            $identifier = end($parts);
        }

        return $identifier;
    }

    /**
     * @param MassActionHandlerArgs $args
     *
     * @return MassActionResponse
     * @throws \Exception
     */
    protected function doDelete(MassActionHandlerArgs $args)
    {
        $iteration    = 0;
        $entityName   = $this->getEntityName($args);
        $queryBuilder = $args->getResults()->getSource();
        $results      = new IterableResult($queryBuilder);
        $results->setBufferSize(self::FLUSH_BATCH_SIZE);
        $this->listenerManager->disableListeners(['oro_search.index_listener']);
        // if huge amount data must be deleted
        set_time_limit(0);
        $deletedIds            = [];
        $entityIdentifiedField = $this->getEntityIdentifierField($args);
        /** @var EntityManager $manager */
        $manager = $this->registry->getManagerForClass($entityName);
        foreach ($results as $result) {
            /** @var $result ResultRecordInterface */
            $entity          = $result->getRootEntity();
            $identifierValue = $result->getValue($entityIdentifiedField);
            if (!$entity) {
                // no entity in result record, it should be extracted from DB
                $entity = $manager->getReference($entityName, $identifierValue);
            }

            if ($entity) {
                if (!$this->securityFacade->isGranted('DELETE', $entity)) {
                    continue;
                }
                $deletedIds[] = $identifierValue;
                $this->processDelete($entity, $manager);
                $iteration++;

                if ($iteration % self::FLUSH_BATCH_SIZE == 0) {
                    $this->finishBatch($manager, $entityName, $deletedIds);
                    $deletedIds = [];
                }
            }
        }

        if ($iteration % self::FLUSH_BATCH_SIZE > 0) {
            $this->finishBatch($manager, $entityName, $deletedIds);
        }

        return $this->getDeleteResponse($args, $iteration);
    }

    /**
     * @param object $entity
     * @param EntityManager $manager
     *
     * @return DeleteMassActionHandler
     */
    protected function processDelete($entity, EntityManager $manager)
    {
        $manager->remove($entity);

        return $this;
    }
}
