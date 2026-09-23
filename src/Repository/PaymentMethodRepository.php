<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Entity\PaymentMethod;
use App\Persistence\Criteria;
use App\Security\Scope;
use DateTimeImmutable;

/**
 * Payment methods belong to a household, as categories do, so the owner column
 * is absent and ISOLATED mode does not hide them. They are still fully
 * household-scoped.
 */
final class PaymentMethodRepository extends AbstractScopedRepository
{
    protected function table(): string
    {
        return 'payment_methods';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'household_id', 'name'];
    }

    protected function ownerColumn(): ?string
    {
        return null;
    }

    /**
     * @return list<PaymentMethod>
     */
    public function findAll(Scope $scope): array
    {
        $rows = $this->findAllScoped(
            $scope,
            Criteria::new()->orderBy('name'),
            $this->qualify('name') . ' ASC',
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function find(Scope $scope, int $id): ?PaymentMethod
    {
        $row = $this->findOneScoped($scope, Criteria::new()->equals('id', $id));

        return $row === null ? null : $this->hydrate($row);
    }

    public function create(Scope $scope, string $name, ?string $colour, ?string $icon, ?string $logoPath): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->insertScoped($scope, [
            'name' => $name,
            'colour' => $colour,
            'icon' => $icon,
            'logo_path' => $logoPath,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function update(Scope $scope, int $id, string $name, ?string $colour): void
    {
        $this->updateScoped($scope, $id, [
            'name' => $name,
            'colour' => $colour,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function setLogo(Scope $scope, int $id, ?string $logoPath): void
    {
        $this->updateScoped($scope, $id, [
            'logo_path' => $logoPath,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function delete(Scope $scope, int $id): void
    {
        $this->deleteScoped($scope, $id);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PaymentMethod
    {
        return new PaymentMethod(
            id: (int) $row['id'],
            householdId: (int) $row['household_id'],
            name: (string) $row['name'],
            colour: isset($row['colour']) ? (string) $row['colour'] : null,
            icon: isset($row['icon']) ? (string) $row['icon'] : null,
            logoPath: isset($row['logo_path']) ? (string) $row['logo_path'] : null,
        );
    }
}
