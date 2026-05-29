<?php

namespace App\Controller\UserManagement;

use App\Repository\PermissionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/permissions', name: 'api_permissions_')]
class PermissionController extends AbstractController
{
    public function __construct(
        private PermissionRepository $permissionRepository,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $search    = (string) $request->query->get('search', '');
        $page      = max(1, $request->query->getInt('page', 1));
        $perPage   = min(100, max(1, $request->query->getInt('per_page', 20)));
        $sortField = $request->query->get('sort', 'name');
        $direction = strtoupper($request->query->get('direction', 'asc')) === 'DESC' ? 'DESC' : 'ASC';

        $filterName = (string) $request->query->get('filter_name', '');

        $allowedSorts = ['name' => 'p.name', 'group' => 'p.group', 'label' => 'p.label'];
        $orderBy = $allowedSorts[$sortField] ?? 'p.name';

        $qb = $this->permissionRepository->createQueryBuilder('p');

        if ($search !== '') {
            $qb->andWhere('p.name LIKE :search OR p.label LIKE :search OR p.group LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }
        if ($filterName !== '') {
            $qb->andWhere('p.name LIKE :fname OR p.label LIKE :fname')
               ->setParameter('fname', '%' . $filterName . '%');
        }

        $countQb = clone $qb;
        $total   = (int) $countQb->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();

        $permissions = $qb->select('p')->orderBy($orderBy, $direction)
                          ->setFirstResult(($page - 1) * $perPage)
                          ->setMaxResults($perPage)
                          ->getQuery()->getResult();

        $lastPage = max(1, (int) ceil($total / $perPage));
        $from     = $total > 0 ? ($page - 1) * $perPage + 1 : 0;
        $to       = min($page * $perPage, $total);

        return $this->json([
            'data'         => array_map(fn ($p) => $this->serialize($p), $permissions),
            'total'        => $total,
            'current_page' => $page,
            'last_page'    => $lastPage,
            'per_page'     => $perPage,
            'from'         => $from,
            'to'           => $to,
        ]);
    }

    private function serialize(\App\Entity\Permission $p): array
    {
        return [
            'id'    => $p->id,
            'name'  => $p->name,
            'label' => $p->label,
            'group' => $p->group,
        ];
    }
}
