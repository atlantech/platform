<?php
namespace Oro\Bundle\IntegrationBundle\Async;

use Doctrine\ORM\EntityManagerInterface;

use Psr\Log\LoggerInterface;

use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\IntegrationBundle\Entity\Channel as Integration;
use Oro\Bundle\IntegrationBundle\Exception\LogicException;
use Oro\Bundle\IntegrationBundle\Manager\TypesRegistry;
use Oro\Bundle\IntegrationBundle\Provider\ReverseSyncProcessor;
use Oro\Bundle\IntegrationBundle\Provider\TwoWaySyncConnectorInterface;
use Oro\Component\MessageQueue\Client\TopicSubscriberInterface;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Job\JobRunner;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use Oro\Component\MessageQueue\Util\JSON;

class ReversSyncIntegrationProcessor implements
    MessageProcessorInterface,
    ContainerAwareInterface,
    TopicSubscriberInterface
{
    use ContainerAwareTrait;

    /**
     * @var DoctrineHelper
     */
    private $doctrineHelper;
    
    /**
     * @var ReverseSyncProcessor
     */
    private $reverseSyncProcessor;

    /**
     * @var TypesRegistry
     */
    private $typesRegistry;

    /**
     * @var JobRunner
     */
    private $jobRunner;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param DoctrineHelper $doctrineHelper
     * @param ReverseSyncProcessor $reverseSyncProcessor
     * @param TypesRegistry $typesRegistry
     * @param JobRunner $jobRunner,
     * @param LoggerInterface $logger
     */
    public function __construct(
        DoctrineHelper $doctrineHelper,
        ReverseSyncProcessor $reverseSyncProcessor,
        TypesRegistry $typesRegistry,
        JobRunner $jobRunner,
        LoggerInterface $logger
    ) {
        $this->doctrineHelper = $doctrineHelper;
        $this->reverseSyncProcessor = $reverseSyncProcessor;
        $this->typesRegistry = $typesRegistry;
        $this->jobRunner = $jobRunner;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedTopics()
    {
        return [Topics::REVERS_SYNC_INTEGRATION];
    }

    /**
     * {@inheritdoc}
     */
    public function process(MessageInterface $message, SessionInterface $session)
    {
        $body = JSON::decode($message->getBody());
        $body = array_replace_recursive(
            [
                'integration_id' => null,
                'connector' => null,
                'connector_parameters' => [],
            ],
            $body
        );

        if (! $body['integration_id'] || ! $body['connector']) {
            $this->logger->critical(
                sprintf(
                    'Invalid message: integration_id and connector should not be empty: %s',
                    $message->getBody()
                ),
                ['message' => $message]
            );

            return self::REJECT;
        }

        $jobName = 'oro_integration:revers_sync_integration:'.$body['integration_id'];
        $ownerId = $message->getMessageId();

        /** @var EntityManagerInterface $em */
        $em = $this->doctrineHelper->getEntityManagerForClass(Integration::class);

        /** @var Integration $integration */
        $integration = $em->find(Integration::class, $body['integration_id']);
        if (! $integration || ! $integration->isEnabled()) {
            $this->logger->critical(
                sprintf('Integration should exist and be enabled: %s', $body['integration_id']),
                ['message' => $message]
            );

            return self::REJECT;
        }

        $em->getConnection()->getConfiguration()->setSQLLogger(null);

        try {
            $connector = $this->typesRegistry->getConnectorType($integration->getType(), $body['connector']);
        } catch (LogicException $e) { //can't find the connector
            $this->logger->critical(
                sprintf('Connector not found: %s', $body['connector']),
                ['message' => $message]
            );
            return self::REJECT;
        }
        if (!$connector instanceof TwoWaySyncConnectorInterface) {
            $this->logger->critical(
                sprintf(
                    'Unable to perform reverse sync for integration "%s" and connector type "%s"',
                    $integration->getId(),
                    $body['connector']
                ),
                ['message' => $message]
            );

            return self::REJECT;
        }

        $result = $this->jobRunner->runUnique($ownerId, $jobName, function () use ($integration, $body) {
            $this->reverseSyncProcessor->process(
                $integration,
                $body['connector'],
                $body['connector_parameters']
            );

            return true;
        });

        return $result ? self::ACK : self::REJECT;
    }
}
