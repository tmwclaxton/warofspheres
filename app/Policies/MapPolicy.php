<?php

namespace App\Policies;

use App\Models\Map;
use App\Models\User;

class MapPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(?User $user, Map $map): bool
    {
        if ($map->published) {
            return true;
        }

        return $user !== null && $user->id === $map->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Map $map): bool
    {
        return $user->id === $map->user_id && ! $map->published;
    }

    public function delete(User $user, Map $map): bool
    {
        return $user->id === $map->user_id;
    }

    public function publish(User $user, Map $map): bool
    {
        return $user->id === $map->user_id && ! $map->published;
    }

    public function fork(User $user, Map $map): bool
    {
        return $map->published;
    }

    public function vote(User $user, Map $map): bool
    {
        return $map->published;
    }
}
