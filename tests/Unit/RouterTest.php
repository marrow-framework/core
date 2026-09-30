<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Container;
use Marrow\Exceptions\HttpException;
use Marrow\Routing\Route;
use Marrow\Routing\Router;
use Marrow\Tests\Unit\Fixtures\AttributeRoutedController;
use Marrow\Tests\Unit\Fixtures\MultiPrefixRoutedController;
use Marrow\Tests\Unit\Fixtures\RouterArticleStub;

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->router = new Router(new Container());
});

test('GET route registered and retrievable by name', function () {
    $this->router->get('/hello', fn () => 'hi')->name('hello');
    $route = $this->router->getRoutes()->getByName('hello');
    expect($route)->not->toBeNull();
    expect($route->getUri())->toBe('/hello');
});

test('route matches URI and extracts params', function () {
    $route  = new Route('GET', '/users/{id}', fn () => null);
    $params = $route->match('/users/42');
    expect($params)->toBeArray();
    expect($params['id'])->toBe('42');
});

test('route returns null on no match', function () {
    $route = new Route('GET', '/users/{id}', fn () => null);
    expect($route->match('/posts/42'))->toBeNull();
});

test('optional param matches without segment', function () {
    $route  = new Route('GET', '/page/{num?}', fn () => null);
    $params = $route->match('/page');
    expect($params)->toBeArray();
    expect($params)->not->toHaveKey('num');
});

test('where constraint allows numeric only', function () {
    $route = (new Route('GET', '/items/{id}', fn () => null))->where('id', '[0-9]+');
    expect($route->match('/items/5'))->not->toBeNull();
    expect($route->match('/items/abc'))->toBeNull();
});

test('generateUrl fills in parameters', function () {
    $route = new Route('GET', '/users/{id}/profile', fn () => null);
    expect($route->generateUrl(['id' => '7']))->toBe('/users/7/profile');
});

test('group prefix prepended to routes', function () {
    $this->router->group(['prefix' => '/api'], function ($r) {
        $r->get('/users', fn () => null)->name('api.users');
    });
    $route = $this->router->getRoutes()->getByName('api.users');
    expect($route)->not->toBeNull();
    expect($route->getUri())->toBe('/api/users');
});

test('405 thrown on wrong HTTP method', function () {
    $this->router->get('/only-get', fn () => null);
    try {
        $this->router->getRoutes()->match('POST', '/only-get');
        $this->fail('Expected HttpException');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(405);
    }
});

test('404 thrown on missing route', function () {
    try {
        $this->router->getRoutes()->match('GET', '/nonexistent-path-xyz');
        $this->fail('Expected HttpException');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(404);
    }
});

test('resource registers seven named routes', function () {
    $this->router->resource('articles', RouterArticleStub::class);
    $names = [
        'articles.index', 'articles.create', 'articles.store',
        'articles.show',  'articles.edit',   'articles.update', 'articles.destroy',
    ];
    foreach ($names as $name) {
        expect($this->router->getRoutes()->getByName($name))
            ->not->toBeNull("Route {$name} not found");
    }
});

test('named URL generation', function () {
    $this->router->get('/profile/{user}', fn () => null)->name('profile');
    expect($this->router->route('profile', ['user' => 'alice']))->toBe('/profile/alice');
});

test('getCurrentRoute is null until dispatch() matches a route', function () {
    expect($this->router->getCurrentRoute())->toBeNull();
});

test('getCurrentRoute reflects the most recently dispatched route', function () {
    $this->router->get('/ping', fn () => new \Marrow\Http\Response('pong'))->name('ping');

    $this->router->dispatch(\Marrow\Http\Request::create('/ping'));

    expect($this->router->getCurrentRoute())->not->toBeNull();
    expect($this->router->getCurrentRoute()->getName())->toBe('ping');
});

test('auth() defaults to the "auth" alias for the session guard', function () {
    $route = (new Route('GET', '/dashboard', fn () => null))->auth();
    expect($route->getMiddlewares())->toBe(['auth']);
});

test('auth() with an explicit guard produces "auth:guard"', function () {
    $route = (new Route('GET', '/api/me', fn () => null))->auth('jwt');
    expect($route->getMiddlewares())->toBe(['auth:jwt']);
});

test('throttle() defaults to 60 attempts per minute', function () {
    $route = (new Route('POST', '/login', fn () => null))->throttle();
    expect($route->getMiddlewares())->toBe(['throttle:60,1']);
});

test('throttle() with explicit values', function () {
    $route = (new Route('POST', '/login', fn () => null))->throttle(5, 1);
    expect($route->getMiddlewares())->toBe(['throttle:5,1']);
});

test('auth(), throttle() and middleware() all chain onto the same route', function () {
    $route = (new Route('POST', '/login', fn () => null))
        ->name('login')
        ->auth('jwt')
        ->throttle(5, 1)
        ->middleware('sanitize');

    expect($route->getName())->toBe('login');
    expect($route->getMiddlewares())->toBe(['auth:jwt', 'throttle:5,1', 'sanitize']);
});

test('controller() registers every #[Route]-attributed method', function () {
    $this->router->controller(AttributeRoutedController::class);

    $index = $this->router->getRoutes()->getByName('posts.index');
    expect($index)->not->toBeNull();
    expect($index->getUri())->toBe('/posts/');
    expect($index->getMethod())->toBe('GET');
    expect($index->getAction())->toBe([AttributeRoutedController::class, 'index']);

    $update = $this->router->getRoutes()->getByName('posts.update');
    expect($update)->not->toBeNull();
    expect($update->getMethod())->toBe('POST');
});

test('controller() merges the class-level #[Route] middleware with the method-level one', function () {
    $this->router->controller(AttributeRoutedController::class);

    $index = $this->router->getRoutes()->getByName('posts.index');
    expect($index->getMiddlewares())->toBe(['web']);

    $update = $this->router->getRoutes()->getByName('posts.update');
    expect($update->getMiddlewares())->toBe(['web', 'auth']);
});

test('controller() honors the current group() prefix, same as get()/post()', function () {
    $this->router->group(['prefix' => '/admin'], function ($r) {
        $r->controller(AttributeRoutedController::class);
    });

    $index = $this->router->getRoutes()->getByName('posts.index');
    expect($index->getUri())->toBe('/admin/posts/');
});

test('controller() registers every repeated class-level #[Route], not just the first', function () {
    $this->router->controller(MultiPrefixRoutedController::class);

    // Both prefixes must actually match — getByName() alone can't tell the
    // two apart, since the method-level #[Route] shares one name across
    // both registrations.
    [$postsRoute] = $this->router->getRoutes()->match('GET', '/posts/5');
    [$articlesRoute] = $this->router->getRoutes()->match('GET', '/articles/5');

    expect($postsRoute->getUri())->toBe('/posts/{id}');
    expect($articlesRoute->getUri())->toBe('/articles/{id}');
});
