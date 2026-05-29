<?php
// src/Service/Cloud/TraefikLabelBuilder.php
namespace App\Service\Cloud;

class TraefikLabelBuilder
{
    public function __construct(
        private readonly string $baseDomain,
        private readonly string $certResolver,
    ) {}

    /**
     * Builds the Traefik Docker labels for a hosted app container.
     *
     * @param string   $subdomain     e.g. "monblog-a1b2c3"
     * @param int      $port          internal container port
     * @param string[] $customDomains additional verified hostnames
     * @return array<string, string>
     */
    public function build(string $subdomain, int $port, array $customDomains): array
    {
        $name  = $this->routerName($subdomain);
        $hosts = ["Host(`{$subdomain}.{$this->baseDomain}`)"];
        foreach ($customDomains as $domain) {
            $hosts[] = "Host(`{$domain}`)";
        }
        $rule = implode(' || ', $hosts);

        return [
            'traefik.enable' => 'true',
            "traefik.http.routers.{$name}.rule"                            => $rule,
            "traefik.http.routers.{$name}.entrypoints"                     => 'websecure',
            "traefik.http.routers.{$name}.tls.certresolver"                => $this->certResolver,
            "traefik.http.services.{$name}.loadbalancer.server.port"       => (string) $port,
        ];
    }

    public function routerName(string $subdomain): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $subdomain));
        $slug = trim($slug, '-');
        return 'jambo-' . $slug;
    }
}
