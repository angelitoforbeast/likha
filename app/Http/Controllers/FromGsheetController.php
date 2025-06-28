<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FromGsheet;

use Illuminate\Support\Collection;

class FromGsheetController extends Controller
{
   
    												
    public function index()
    {
        //$data = FromGsheet::all(); // kunin lahat ng rows
        $data = FromGsheet::paginate(10);
        return view('from_gsheet', compact('data'));
    }





}
