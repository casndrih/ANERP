<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Support\CompanyAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerCreditController extends Controller
{
    public function index(Request $request)
    {
        CompanyAuthorizer::requirePermission($request, 'finance.view');
        $companyId = CompanyAuthorizer::activeCompanyId($request);

        return view('finance.customer-credit', [
            'customers' => Customer::where('company_id', $companyId)
                ->orderBy('name')->paginate(50),
        ]);
    }

    public function update(Request $request, int $customer)
    {
        CompanyAuthorizer::requireRole($request, ['owner', 'admin']);
        $companyId = CompanyAuthorizer::activeCompanyId($request);
        $data = $request->validate([
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'credit_hold' => ['required', 'boolean'],
            'credit_hold_reason' => ['nullable', 'string', 'max:255', 'required_if:credit_hold,1'],
        ]);

        $record = Customer::where('company_id', $companyId)->findOrFail($customer);
        $record->update([
            'credit_limit' => $data['credit_limit'] === null || $data['credit_limit'] === ''
                ? null : round((float) $data['credit_limit'], 2),
            'credit_hold' => (bool) $data['credit_hold'],
            'credit_hold_reason' => (bool) $data['credit_hold']
                ? ($data['credit_hold_reason'] ?? null) : null,
        ]);

        return back()->with('status', 'Customer credit controls updated.');
    }
}
