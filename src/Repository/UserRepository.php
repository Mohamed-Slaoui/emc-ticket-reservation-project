<?php

namespace App\Repository;

use App\DTO\Pagination;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function save(User $user): void
    {
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function remove(User $user): void
    {
        $this->getEntityManager()->remove($user);
        $this->getEntityManager()->flush();
    }

    /**
     * The number of accounts that carry the administrator role.
     *
     * The roles are stored as a JSON array, so the membership is checked by
     * the database rather than by a fragile string comparison.
     */
    public function countAdministrators(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('JSON_CONTAINS(u.roles, :role) = 1')
            ->setParameter('role', '"ROLE_ADMIN"')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * A page of accounts, most recently created first.
     *
     * @return array{0: list<User>, 1: Pagination}
     */
    public function paginate(int $page = 1, int $perPage = Pagination::DEFAULT_PER_PAGE, ?string $search = null): array
    {
        $query = $this->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC')
            ->addSelect('u');

        if (null !== $search && '' !== trim($search)) {
            $term = '%'.trim($search).'%';
            $query
                ->andWhere('u.email LIKE :search OR u.firstName LIKE :search OR u.lastName LIKE :search')
                ->setParameter('search', $term);
        }

        $countQuery = clone $query;
        $total = (int) $countQuery
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $pagination = Pagination::create($page, $perPage, $total);

        /** @var list<User> $users */
        $users = $query
            ->setFirstResult($pagination->getOffset())
            ->setMaxResults($pagination->perPage)
            ->getQuery()
            ->getResult();

        return [$users, $pagination];
    }
}
