<?php

namespace App\Controller;

use App\Entity\EndUser;

trait EndUserSerializerTrait
{
    private function serializeEndUser(EndUser $eu): array
    {
        return [
            'uuid'          => $eu->uuid?->toString(),
            'email'         => $eu->email,
            'name'          => $eu->name,
            'status'        => $eu->status,
            'avatar_url'    => $eu->avatarUrl,
            'custom_fields' => $eu->customFields,
            'created_at'    => $eu->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at'    => $eu->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
