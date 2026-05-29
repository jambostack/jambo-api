<?php
// src/Service/Cloud/DnsResolverInterface.php
namespace App\Service\Cloud;

interface DnsResolverInterface
{
    /**
     * Return all TXT record values for a hostname.
     * @return string[]
     */
    public function txtRecords(string $hostname): array;
}
