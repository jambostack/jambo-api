<?php

namespace App\Entity;

use App\Repository\AppSettingsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[ORM\Entity(repositoryClass: AppSettingsRepository::class)]
#[Vich\Uploadable]
class AppSettings
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id = 1;

    #[ORM\Column(length: 100)]
    public string $appName = 'JamboAPI';

    // Generic logo (fallback)
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $logoFileName = null;

    #[Vich\UploadableField(mapping: 'app_media', fileNameProperty: 'logoFileName')]
    public ?File $logoFile = null;

    // Logo shown in light mode (dark-colored logo)
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $logoDarkFileName = null;

    #[Vich\UploadableField(mapping: 'app_media', fileNameProperty: 'logoDarkFileName')]
    public ?File $logoDarkFile = null;

    // Logo shown in dark mode (light-colored logo)
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $logoLightFileName = null;

    #[Vich\UploadableField(mapping: 'app_media', fileNameProperty: 'logoLightFileName')]
    public ?File $logoLightFile = null;

    // Icon for collapsed sidebar in light mode (dark-colored icon)
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $iconDarkFileName = null;

    #[Vich\UploadableField(mapping: 'app_media', fileNameProperty: 'iconDarkFileName')]
    public ?File $iconDarkFile = null;

    // Icon for collapsed sidebar in dark mode (light-colored icon)
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $iconLightFileName = null;

    #[Vich\UploadableField(mapping: 'app_media', fileNameProperty: 'iconLightFileName')]
    public ?File $iconLightFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $faviconFileName = null;

    #[Vich\UploadableField(mapping: 'app_media', fileNameProperty: 'faviconFileName')]
    public ?File $faviconFile = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $updatedAt = null;

    /** Clés/URLs fournisseurs IA stockées en DB (override des vars env) */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $aiProviders = null;

    public function getLogoUrl(): ?string
    {
        return $this->logoFileName ? '/uploads/app/' . $this->logoFileName : null;
    }

    public function getLogoDarkUrl(): ?string
    {
        return $this->logoDarkFileName ? '/uploads/app/' . $this->logoDarkFileName : null;
    }

    public function getLogoLightUrl(): ?string
    {
        return $this->logoLightFileName ? '/uploads/app/' . $this->logoLightFileName : null;
    }

    public function getIconDarkUrl(): ?string
    {
        return $this->iconDarkFileName ? '/uploads/app/' . $this->iconDarkFileName : null;
    }

    public function getIconLightUrl(): ?string
    {
        return $this->iconLightFileName ? '/uploads/app/' . $this->iconLightFileName : null;
    }

    public function getFaviconUrl(): ?string
    {
        return $this->faviconFileName ? '/uploads/app/' . $this->faviconFileName : null;
    }
}
