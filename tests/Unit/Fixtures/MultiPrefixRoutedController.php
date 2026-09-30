<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

use Marrow\Routing\Attributes\Route;

#[Route('/posts')]
#[Route('/articles')]
class MultiPrefixRoutedController
{
    #[Route('/{id}', name: 'posts.show')]
    public function show(): void
    {
    }
}
