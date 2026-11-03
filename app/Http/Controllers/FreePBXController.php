<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Horsefly\FreePBXCdr;
use Illuminate\Support\Facades\DB;

class FreePBXController extends Controller
{
    public function __construct()
    {
        // Call-detail records from the PBX: admins only (there was no check at all).
        $this->middleware('permission:administrator-setting-index');
    }

    public function index()
    {
        return view('freepbx.cdrs');
    }
    public function getFreepbxAjaxRequest()
    {
        $data = DB::connection('freepbx')
            ->table('cdr')
            ->select('calldate', 'src', 'dst', 'duration', 'disposition')
            ->orderBy('calldate', 'desc')
            ->limit(5)
            ->get();

        return response()->json($data);
    }
}
