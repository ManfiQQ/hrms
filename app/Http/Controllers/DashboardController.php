<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * The landing page after login — deliberately minimal.
 *
 * Its only job today is to be a real authenticated route: somewhere the session gate, the
 * freeze gate and BR-A23's gate can be observed working, and somewhere `intended()` can send
 * a visitor after login. It holds no module content, and the dashboards the spec describes —
 * the BR-A19 lifecycle countdown across five roles — belong to the modules that own that
 * data, not here.
 */
class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('dashboard');
    }
}
