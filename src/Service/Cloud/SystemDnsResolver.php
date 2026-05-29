<?php
// src/Service/Cloud/SystemDnsResolver.php
namespace App\Service\Cloud;

class SystemDnsResolver implements DnsResolverInterface
{
    public function txtRecords(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_TXT);
        if ($records === false) {
            return [];
        }
        $values = [];
        foreach ($records as $r) {
            if (isset($r['txt'])) {
                $values[] = $r['txt'];
            }
        }
        return $values;
    }
}
