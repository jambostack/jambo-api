<?php

namespace App\Repository;

use App\Entity\AppSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppSettings>
 */
class AppSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppSettings::class);
    }

    public function getOrCreate(): AppSettings
    {
        $settings = $this->find(1);
        if ($settings !== null) {
            return $settings;
        }

        try {
            $settings = new AppSettings();
            $this->getEntityManager()->persist($settings);
            $this->getEntityManager()->flush();

            return $settings;
        } catch (UniqueConstraintViolationException) {
            // Another request created the row concurrently — re-fetch.
            return $this->find(1);
        }
    }
}
