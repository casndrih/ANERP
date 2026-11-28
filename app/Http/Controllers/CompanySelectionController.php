<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
class CompanySelectionController extends Controller {
    public function store(Request $request) {
        $data = $request->validate(['company_id'=>['required','integer','exists:companies,id']]);
        abort_unless($request->user()->companies()->where('companies.id',$data['company_id'])->where('companies.is_active',true)->wherePivot('is_active',true)->exists(),403);
        $request->session()->put('active_company_id',(int)$data['company_id']);
        return redirect()->route('dashboard');
    }
}
