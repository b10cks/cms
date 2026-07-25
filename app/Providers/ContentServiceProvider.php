<?php

namespace App\Providers;

use App\Services\Content\ContentValidator;
use App\Services\Content\Schema\TypeRegistry;
use App\Services\Content\Schema\Types\BlockTypeHandler;
use App\Services\Content\Schema\Types\BooleanTypeHandler;
use App\Services\Content\Schema\Types\NumberTypeHandler;
use App\Services\Content\Schema\Types\TextTypeHandler;
use App\Services\Content\Schema\Types\TypeHandlerInterface;
use App\Services\Content\Serial\TemplateRenderer;
use App\Services\Content\Serial\TokenResolver;
use App\Services\Content\Serial\Tokens\AncestorToken;
use App\Services\Content\Serial\Tokens\BlockToken;
use App\Services\Content\Serial\Tokens\CounterToken;
use App\Services\Content\Serial\Tokens\DateToken;
use App\Services\Content\Serial\Tokens\FieldToken;
use App\Services\Content\Serial\Tokens\LanguageToken;
use App\Services\Content\Serial\Tokens\ParentToken;
use App\Services\Database\SpaceModelResolver;
use App\Services\Image\ImageTransformationManager;
use App\Services\Image\ImageTransformationResolver;
use App\Services\Image\ImageTransformationService;
use Illuminate\Support\ServiceProvider;

class ContentServiceProvider extends ServiceProvider
{
    /**
     * List of content types
     * @var array<TypeHandlerInterface>
     */
    protected static array $types = [
        NumberTypeHandler::class,
        TextTypeHandler::class,
        BooleanTypeHandler::class,
        BlockTypeHandler::class,
    ];

    /**
     * Tokens available in serial formats and block slug patterns. Adding a
     * token is adding a class here.
     *
     * @var array<class-string<TokenResolver>>
     */
    protected static array $serialTokens = [
        CounterToken::class,
        ParentToken::class,
        AncestorToken::class,
        FieldToken::class,
        BlockToken::class,
        DateToken::class,
        LanguageToken::class,
    ];

    public function register()
    {
        $this->app->singleton(TemplateRenderer::class, function () {
            return new TemplateRenderer(array_map(
                static fn (string $token): TokenResolver => new $token(),
                self::$serialTokens,
            ));
        });

        $this->app->singleton(SpaceModelResolver::class, function() {
            return new SpaceModelResolver();
        });

        $this->app->singleton(TypeRegistry::class, function () {
            $registry = new TypeRegistry();

            foreach (self::$types as $type) {
                $registry->register(new $type());
            }

            return $registry;
        });

        $this->app->singleton(ContentValidator::class, function ($app) {
            return new ContentValidator($app->make(TypeRegistry::class));
        });

        $this->app->singleton(ImageTransformationManager::class, function ($app) {
            return new ImageTransformationManager($app);
        });

        $this->app->singleton(ImageTransformationService::class, function ($app) {
            return new ImageTransformationService($app->make(ImageTransformationManager::class));
        });

        $this->app->singleton(ImageTransformationResolver::class);
    }
}
