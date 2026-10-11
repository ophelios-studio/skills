<?php

declare(strict_types=1);

namespace App\Controllers;

use Zephyrus\Controller\Controller;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Rendering\RenderResponses;
use Zephyrus\Routing\Attribute\Get;
use Zephyrus\Routing\Attribute\Post;
use Zephyrus\Validation\FormValidator;
use Zephyrus\Validation\Rules;

final class HomeController extends Controller
{
    use RenderResponses;

    #[Get('/')]
    public function index(): Response
    {
        return $this->render('home', ['name' => null, 'errors' => []]);
    }

    #[Post('/greet')]
    public function greet(Request $request): Response
    {
        $form = new FormValidator(['name' => [Rules::required(), Rules::maxLength(40)]]);
        $errors = $form->validate($request->body()->all());

        if ($errors->hasErrors()) {
            return $this->render('home', ['name' => null, 'errors' => $errors->allMessages()], 422);
        }

        return $this->render('home', ['name' => $request->body()->get('name'), 'errors' => []]);
    }

    #[Get('/api/hello/{name}', constraints: ['name' => '[a-z]+'])]
    public function hello(string $name): Response
    {
        return $this->json(['message' => 'Hello, ' . $name]);
    }

    #[Get('/health')]
    public function health(): Response
    {
        return $this->text('ok');
    }
}
