<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Intercepte les requetes entrantes et detecte le contenu encode en
 * Latin-1 (ISO-8859-1 / Windows-1252) envoye accidentellement comme
 * de l'UTF-8. Convertit les bytes Latin-1 en UTF-8 propre pour eviter
 * la corruption des caracteres accentues (stockage de U+FFFD en base).
 *
 * Probleme resolu : les imports CSV/Excel en Latin-1 via l'API JSON
 * produisaient des "conqu�te", "indiff�rent", "�ch�ance" au lieu
 * de "conquete", "indifferent", "echeance".
 */
class Utf8RequestNormalizerSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 250], // Priorite haute, apres le body parsing
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $content = $request->getContent();

        // Ignorer les requetes sans body (GET, HEAD, etc.)
        if ($content === '') {
            return;
        }

        // Ignorer les uploads de fichiers
        if (str_starts_with($request->headers->get('Content-Type', ''), 'multipart/form-data')) {
            return;
        }

        // Verifier si le contenu est deja de l'UTF-8 valide
        if (mb_check_encoding($content, 'UTF-8')) {
            return;
        }

        // Tenter une conversion Latin-1 → UTF-8
        // On utilise utf8_encode() pour ISO-8859-1 puis on verifie
        $converted = @\mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        if ($converted === false || $converted === '') {
            // Fallback: Windows-1252 (gère les caracteres 0x80-0x9F)
            $converted = @\mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        if ($converted !== false && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
            // Reecrire le contenu de la requete avec la version UTF-8 propre
            $request->initialize(
                $request->query->all(),
                $request->request->all(),
                $request->attributes->all(),
                $request->cookies->all(),
                $request->files->all(),
                $request->server->all(),
                $converted
            );
        }
    }
}
