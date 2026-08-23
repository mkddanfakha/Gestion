<?php

namespace App\Http\Controllers\Admin;

use App\Auth\RbacUiPresenter;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class RolesPermissionsController extends Controller
{
    public function __construct(
        private readonly RbacUiPresenter $presenter,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Admin/RolesPermissions/Index', $this->presenter->forIndex());
    }
}
