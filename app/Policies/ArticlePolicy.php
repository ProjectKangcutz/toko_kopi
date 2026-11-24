<?php

namespace App\Policies;

use App\Models\Article;
use App\Models\User;

class ArticlePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('content.view');
    }

    public function view(User $user, Article $article): bool
    {
        return $user->hasPermission('content.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('content.manage');
    }

    public function update(User $user, Article $article): bool
    {
        return $user->hasPermission('content.manage');
    }

    public function delete(User $user, Article $article): bool
    {
        return $user->hasPermission('content.manage');
    }
}
