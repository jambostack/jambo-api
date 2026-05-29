<?php
// src/Service/Cloud/CustomDomainService.php
namespace App\Service\Cloud;

use App\Entity\CustomDomain;
use App\Entity\HostedApp;
use Doctrine\ORM\EntityManagerInterface;

class CustomDomainService
{
    private const CHALLENGE_PREFIX = '_jambo-challenge.';
    private const TXT_PREFIX        = 'jambo-verify=';

    public function __construct(
        private readonly DnsResolverInterface $dns,
        private readonly ?EntityManagerInterface $em = null,
    ) {}

    public function addDomain(HostedApp $hostedApp, string $domain): CustomDomain
    {
        $cd = new CustomDomain();
        $cd->hostedApp         = $hostedApp;
        $cd->domain            = strtolower(trim($domain));
        $cd->verificationToken = bin2hex(random_bytes(16));
        $cd->verified          = false;
        $cd->sslStatus         = CustomDomain::SSL_PENDING;

        $this->em?->persist($cd);
        $this->em?->flush();

        return $cd;
    }

    /** The DNS TXT record name the user must create. */
    public function challengeRecordName(CustomDomain $cd): string
    {
        return self::CHALLENGE_PREFIX . $cd->domain;
    }

    /** The DNS TXT record value the user must set. */
    public function challengeRecordValue(CustomDomain $cd): string
    {
        return self::TXT_PREFIX . $cd->verificationToken;
    }

    public function verify(CustomDomain $cd): bool
    {
        $expected = $this->challengeRecordValue($cd);
        $records  = $this->dns->txtRecords($this->challengeRecordName($cd));

        $ok = in_array($expected, $records, true);
        if ($ok) {
            $cd->verified   = true;
            $cd->verifiedAt = new \DateTimeImmutable();
            $cd->sslStatus  = CustomDomain::SSL_ACTIVE;
            $this->em?->flush();
        }
        return $ok;
    }
}
