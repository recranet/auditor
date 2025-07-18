<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Reader;

use DH\Auditor\Exception\AccessDeniedException;
use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Auditing\Annotation\Security;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\SimpleFilter;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Reader\ReaderTest;
use Doctrine\ORM\Mapping\ClassMetadata as ORMMetadata;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @see ReaderTest
 */
final readonly class Reader implements ReaderInterface
{
    /**
     * @var int
     */
    public const PAGE_SIZE = 50;

    /**
     * Reader constructor.
     */
    public function __construct(private DoctrineProvider $provider) {}

    public function getProvider(): DoctrineProvider
    {
        return $this->provider;
    }

    public function createQuery(string $entity, array $options = []): Query
    {
        $this->checkAuditable($entity);
        $this->checkRoles($entity, Security::VIEW_SCOPE);

        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);
        $config = $resolver->resolve($options);

        $connection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();
        $timezone = $this->provider->getAuditor()->getConfiguration()->getTimezone();

        $query = new Query(
            $this->getEntityAuditTableName($entity),
            $connection,
            $this->provider->getConfiguration(),
            $timezone,
        );

        $query
            ->addOrderBy(Query::CREATED_AT, 'DESC')
            ->addOrderBy(Query::ID, 'DESC')
        ;

        if (null !== $config['type']) {
            $query->addFilter(new SimpleFilter(Query::TYPE, $config['type']));
        }

        if (null !== $config['object_id']) {
            $query->addFilter(new SimpleFilter(Query::OBJECT_ID, $config['object_id']));
        }

        // user_id is considered as an alias for blame_id
        // if both are provided, blame_id will be used
        if (null !== $config['blame_id'] || null !== $config['user_id']) {
            $blame_id = $config['blame_id'] ?? $config['user_id'];
            $query->addFilter(new SimpleFilter(Query::USER_ID, $blame_id));
        }

        if (null !== $config['transaction_hash']) {
            $query->addFilter(new SimpleFilter(Query::TRANSACTION_HASH, $config['transaction_hash']));
        }

        if (null !== $config['page'] && null !== $config['page_size']) {
            $query->limit($config['page_size'], ($config['page'] - 1) * $config['page_size']);
        }

        /** @var AuditingService $auditingService */
        $auditingService = $this->provider->getAuditingServiceForEntity($entity);
        $metadata = $auditingService->getEntityManager()->getClassMetadata($entity);
        if (
            $config['strict']
            && $metadata instanceof ORMMetadata
            && ORMMetadata::INHERITANCE_TYPE_SINGLE_TABLE === $metadata->inheritanceType
        ) {
            $query->addFilter(new SimpleFilter(Query::DISCRIMINATOR, $entity));
        }

        foreach ($this->provider->getConfiguration()->getExtraIndices() as $indexedField => $extraIndexConfig) {
            if (null !== $config[$indexedField]) {
                $query->addFilter(new SimpleFilter($indexedField, $config[$indexedField]));
            }
        }

        return $query;
    }

    /**
     * Returns an array of all audited entries/operations for a given transaction hash
     * indexed by entity FQCN.
     */
    public function getAuditsByTransactionHash(string $transactionHash): array
    {
        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();
        $results = [];

        $entities = $configuration->getEntities();
        foreach (array_keys($entities) as $entity) {
            try {
                $audits = $this->createQuery($entity, ['transaction_hash' => $transactionHash, 'page_size' => null])->execute();
                if ([] !== $audits) {
                    $results[$entity] = $audits;
                }
            } catch (AccessDeniedException) {
                // access denied
            }
        }

        return $results;
    }

    /**
     * @return array{results: \ArrayIterator<int|string, Entry>, currentPage: int, hasPreviousPage: bool, hasNextPage: bool, previousPage: null|int, nextPage: null|int, numPages: int, haveToPaginate: bool, numResults: int, pageSize: int}
     */
    public function paginate(Query $query, int $page = 1, ?int $pageSize = null): array
    {
        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();
        $pageSize ??= $configuration->getViewerPageSize();
        $numResults = $query->count();
        $currentPage = max(1, $page);
        $hasPreviousPage = $currentPage > 1;
        $hasNextPage = ($currentPage * $pageSize) < $numResults;

        return [
            'results' => new \ArrayIterator($query->execute()),
            'currentPage' => $currentPage,
            'hasPreviousPage' => $hasPreviousPage,
            'hasNextPage' => $hasNextPage,
            'previousPage' => $hasPreviousPage ? $currentPage - 1 : null,
            'nextPage' => $hasNextPage ? $currentPage + 1 : null,
            'numPages' => (int) ceil($numResults / $pageSize),
            'haveToPaginate' => $numResults > $pageSize,
            'numResults' => $numResults,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Returns the table name of $entity.
     */
    public function getEntityTableName(string $entity): string
    {
        /** @var AuditingService $auditingService */
        $auditingService = $this->provider->getAuditingServiceForEntity($entity);

        return $auditingService->getEntityManager()->getClassMetadata($entity)->getTableName();
    }

    /**
     * Returns the audit table name for $entity.
     */
    public function getEntityAuditTableName(string $entity): string
    {
        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();

        /** @var AuditingService $auditingService */
        $auditingService = $this->provider->getAuditingServiceForEntity($entity);
        $entityManager = $auditingService->getEntityManager();
        $schema = '';
        if ($entityManager->getClassMetadata($entity)->getSchemaName()) {
            $schema = $entityManager->getClassMetadata($entity)->getSchemaName().'.';
        }

        return \sprintf(
            '%s%s%s%s',
            $schema,
            $configuration->getTablePrefix(),
            $this->getEntityTableName($entity),
            $configuration->getTableSuffix()
        );
    }

    private function configureOptions(OptionsResolver $resolver): void
    {
        // https://symfony.com/doc/current/components/options_resolver.html
        $resolver
            ->setDefaults([
                'type' => null,
                'object_id' => null,
                'blame_id' => null,
                'user_id' => null,
                'transaction_hash' => null,
                'page' => 1,
                'page_size' => self::PAGE_SIZE,
                'strict' => true,
            ])
            ->setAllowedTypes('type', ['null', 'string', 'array'])
            ->setAllowedTypes('object_id', ['null', 'int', 'string', 'array'])
            ->setAllowedTypes('blame_id', ['null', 'int', 'string', 'array'])
            ->setAllowedTypes('user_id', ['null', 'int', 'string', 'array'])
            ->setAllowedTypes('transaction_hash', ['null', 'string', 'array'])
            ->setAllowedTypes('page', ['null', 'int'])
            ->setAllowedTypes('page_size', ['null', 'int'])
            ->setAllowedTypes('strict', ['null', 'bool'])
            ->setAllowedValues('page', static fn (?int $value): bool => null === $value || $value >= 1)
            ->setAllowedValues('page_size', static fn (?int $value): bool => null === $value || $value >= 1)
        ;

        foreach ($this->provider->getConfiguration()->getExtraIndices() as $indexedField => $extraIndexConfig) {
            $resolver->setDefault($indexedField, null);
            $resolver->setAllowedTypes($indexedField, ['null', 'int', 'string', 'array']);
        }
    }

    /**
     * Throws an InvalidArgumentException if given entity is not auditable.
     *
     * @throws InvalidArgumentException
     */
    private function checkAuditable(string $entity): void
    {
        if (!$this->provider->isAuditable($entity)) {
            throw new InvalidArgumentException('Entity '.$entity.' is not auditable.');
        }
    }

    /**
     * Throws an AccessDeniedException if user not is granted to access audits for the given entity.
     *
     * @throws AccessDeniedException
     */
    private function checkRoles(string $entity, string $scope): void
    {
        $roleChecker = $this->provider->getAuditor()->getConfiguration()->getRoleChecker();

        if (null === $roleChecker || $roleChecker($entity, $scope)) {
            return;
        }

        // access denied
        throw new AccessDeniedException('You are not allowed to access audits of "'.$entity.'" entity.');
    }
}
