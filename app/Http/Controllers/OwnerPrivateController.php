<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

class OwnerPrivateController extends Controller
{
    public function index()
    {
        if (Auth::id() !== 1) abort(404);

        return view('owner.private');
    }
}
