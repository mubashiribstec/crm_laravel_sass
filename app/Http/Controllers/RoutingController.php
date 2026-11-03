<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class RoutingController extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    public function __construct()
    {
        $this->
        middleware('auth')->
        except('index');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        if (Auth::user()) {
            return redirect('/dashboard');
        } else {
            return redirect('login');
        }
    }

    /**
     * Display a view based on first route param
     *
     * @return \Illuminate\Http\Response
     */
    public function root(Request $request, $first)
    {
        return $this->page($first);
    }

    /**
     * second level route
     */
    public function secondLevel(Request $request, $first, $second)
    {
        return $this->page($first . '.' . $second);
    }

    /**
     * third level route
     */
    public function thirdLevel(Request $request, $firstLevel, $secondLevel, $thirdLevel)
    {
        // Check if the request is for .well-known
        if ($firstLevel === '.well-known') {
            // Handle specific .well-known paths
            if ($secondLevel === 'appspecific' && $thirdLevel === 'com.chrome.devtools.json') {
                // Option 1: Return a 404 if the file doesn't exist
                abort(404, 'File not found');

                // Option 2: Serve a static JSON file if it exists
                // $filePath = public_path('.well-known/appspecific/com.chrome.devtools.json');
                // if (file_exists($filePath)) {
                //     return response()->file($filePath);
                // }
                // abort(404, 'File not found');

                // Option 3: Return a JSON response
                // return response()->json(['error' => 'Not supported'], 404);
            }
        }

        // Existing logic for other routes
        return $this->page("$firstLevel.$secondLevel.$thirdLevel");
    }

    /** A theme page by view name; an address with no such view is a 404, not a server error. */
    private function page(string $name)
    {
        abort_unless(view()->exists($name), 404);

        return view($name);
    }
}
