<?php

namespace App\Core\Security;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;

class AdminController extends Controller
{
    /**
     * @return RedirectResponse
     */
    public function storageLink(): RedirectResponse
    {
        // check if the storage folder already linked;
        if(File::exists(public_path('storage'))){
            // removed the existing symbolic link
            File::delete(public_path('storage'));
            //Regenerate the storage link folder
        }
        try{
            Artisan::call('storage:link');
            request()->session()->flash('success', 'Successfully storage linked.');
            return redirect()->back();
        }
        catch(\Exception $exception){
            request()->session()->flash('error', $exception->getMessage());
            return redirect()->back();
        }
    }
}
