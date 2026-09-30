<?php

declare(strict_types=1);

namespace Marrow\Routing\Attributes;

use Attribute;

/**
 * Declares a route directly on a controller method, as an alternative to
 * `$router->get(...)` in routes.php — registered via `$router->controller(Class::class)`,
 * which must still be called explicitly from a module's routes.php (routing
 * stays module-only; this only changes where a route's metadata lives).
 *
 *   #[Route('/posts')]
 *   class PostController extends Controller
 *   {
 *       #[Route('/', name: 'posts.index')]
 *       public function index(): Response { ... }
 *
 *       #[Route('/{id}', method: 'POST', middleware: 'auth')]
 *       public function update(int|string $id): Response { ... }
 *   }
 *
 * A class-level #[Route] supplies a URI prefix (name/method are ignored
 * there) and middleware shared by every attributed method on the class.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Route
{
    /**
     * @param string|string[] $method
     * @param string|string[] $middleware
     */
    public function __construct(
        public readonly string $uri = '',
        public readonly string|array $method = 'GET',
        public readonly ?string $name = null,
        public readonly string|array $middleware = [],
    ) {
    }
}
