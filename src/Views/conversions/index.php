<div class="py-6">
    <?php
    $enabledPayoutMethods = array_filter(array_map('trim', explode(',', (string)($settings['enabled_payout_methods'] ?? 'stripe_customer_balance'))));
    $adminStripeEnabled = in_array('stripe_customer_balance', $enabledPayoutMethods, true);
    ?>
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="sm:flex sm:items-center">
            <div class="sm:flex-auto">
                <h1 class="text-2xl font-semibold text-gray-900">Conversions</h1>
                <p class="mt-2 text-sm text-gray-700">A list of all conversions from your affiliate programs.</p>
            </div>
            <div class="mt-4 sm:ml-16 sm:mt-0 sm:flex-none">
                <a href="/admin/conversions/export" class="block rounded-md bg-white px-3 py-2 text-center text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                    Export CSV
                </a>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="rounded-md bg-green-50 p-4 mt-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-green-800"><?= htmlspecialchars($_SESSION['success']) ?></p>
                    </div>
                </div>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="rounded-md bg-red-50 p-4 mt-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-red-800"><?= htmlspecialchars($_SESSION['error']) ?></p>
                    </div>
                </div>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Filter Form -->
        <form method="GET" action="/admin/conversions" class="mt-8 flex items-center gap-4">
            <div class="flex-1">
                <select id="status" name="status" class="block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6">
                    <option value="all" <?= ($filters['status'] ?? 'all') === 'all' ? 'selected' : '' ?>>All Status</option>
                    <option value="pending" <?= ($filters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="payable" <?= ($filters['status'] ?? '') === 'payable' ? 'selected' : '' ?>>Payable</option>
                    <option value="paid" <?= ($filters['status'] ?? '') === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="rejected" <?= ($filters['status'] ?? '') === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                </select>
            </div>

            <div class="flex-1">
                <select id="partner_id" name="partner_id" class="block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6">
                    <option value="">All Partners</option>
                    <?php foreach ($partners as $partner): ?>
                        <option value="<?= $partner['id'] ?>" <?= ($filters['partner_id'] ?? '') == $partner['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($partner['company_name'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex-1">
                <select id="program_id" name="program_id" class="block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6">
                    <option value="">All Programs</option>
                    <?php foreach ($programs as $program): ?>
                        <option value="<?= $program['id'] ?>" <?= ($filters['program_id'] ?? '') == $program['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($program['name'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <input type="date" name="start_date" id="start_date"
                    value="<?= htmlspecialchars($filters['start_date'] ?? '') ?>"
                    placeholder="Start Date"
                    class="block w-40 rounded-md border-0 py-1.5 pl-3 pr-3 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6">
                <span class="text-gray-500">-</span>
                <input type="date" name="end_date" id="end_date"
                    value="<?= htmlspecialchars($filters['end_date'] ?? '') ?>"
                    placeholder="End Date"
                    class="block w-40 rounded-md border-0 py-1.5 pl-3 pr-3 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6">
            </div>

            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500">
                Apply Filters
            </button>
        </form>

        <!-- Stats Overview -->
        <dl class="mt-8 grid grid-cols-1 gap-5 sm:grid-cols-3">
            <div class="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6">
                <dt class="truncate text-sm font-medium text-gray-500">Total Conversions</dt>
                <dd class="mt-1 text-3xl font-semibold tracking-tight text-gray-900"><?= number_format($totals['count']) ?></dd>
            </div>
            <div class="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6">
                <dt class="truncate text-sm font-medium text-gray-500">Total Revenue</dt>
                <dd class="mt-1 text-3xl font-semibold tracking-tight text-gray-900">$<?= number_format($totals['amount'], 2) ?></dd>
            </div>
            <div class="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6">
                <dt class="truncate text-sm font-medium text-gray-500">Total Commission</dt>
                <dd class="mt-1 text-3xl font-semibold tracking-tight text-gray-900">$<?= number_format($totals['commission'], 2) ?></dd>
            </div>
        </dl>

        <!-- Conversions Table -->
        <?php if (empty($conversions)): ?>
            <div class="mt-8 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">No conversions found</h3>
                <p class="mt-1 text-sm text-gray-500">No conversions match your current filters.</p>
            </div>
        <?php else: ?>
            <div class="mt-8 flow-root">
                <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                    <div class="inline-block min-w-full py-2 align-middle">
                        <table class="min-w-full divide-y divide-gray-300">
                            <thead>
                                <tr>
                                    <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 sm:pl-6">Date</th>
                                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Partner</th>
                                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Program</th>
                                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Customer</th>
                                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Amount</th>
                                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Commission</th>
                                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Status</th>
                                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Payout</th>
                                    <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
                                        <span class="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <?php foreach ($conversions as $conversion): ?>
                                    <tr>
                                        <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm text-gray-900 sm:pl-6">
                                            <?= date('M j, Y', strtotime($conversion['created_at'] ?? '')) ?>
                                            <div class="text-gray-500"><?= date('g:i A', strtotime($conversion['created_at'] ?? '')) ?></div>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-4 text-sm">
                                            <div class="font-medium text-gray-900"><?= htmlspecialchars($conversion['partner_name'] ?? '') ?></div>
                                            <div class="text-gray-500 font-mono text-xs"><?= htmlspecialchars($conversion['tracking_code'] ?? '') ?></div>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                            <?= htmlspecialchars($conversion['program_name'] ?? '') ?>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                            <?= htmlspecialchars($conversion['customer_email'] ?? '') ?>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                            $<?= number_format($conversion['amount'] ?? 0, 2) ?>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                            $<?= number_format($conversion['commission_amount'] ?? 0, 2) ?>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-4 text-sm">
                                            <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium <?php
                                                                                                                            switch ($conversion['status']):
                                                                                                                                case 'pending':
                                                                                                                                    echo 'bg-yellow-50 text-yellow-800';
                                                                                                                                    break;
                                                                                                                                case 'payable':
                                                                                                                                    echo 'bg-green-50 text-green-800';
                                                                                                                                    break;
                                                                                                                                case 'paid':
                                                                                                                                    echo 'bg-blue-50 text-blue-800';
                                                                                                                                    break;
                                                                                                                                case 'rejected':
                                                                                                                                    echo 'bg-red-50 text-red-800';
                                                                                                                                    break;
                                                                                                                                default:
                                                                                                                                    echo 'bg-gray-100 text-gray-800';
                                                                                                                            endswitch; ?>">
                                                <?= ucfirst(htmlspecialchars($conversion['status'])) ?>
                                            </span>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                            <?php if (!empty($conversion['payout_method'])): ?>
                                                <?php if ($conversion['payout_method'] === 'stripe_customer_balance'): ?>
                                                    <span class="inline-flex items-center rounded-md bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700">
                                                        Stripe Customer Balance
                                                    </span>
                                                <?php elseif ($conversion['payout_method'] === 'stripe_transfer'): ?>
                                                    <span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700">
                                                        Stripe Transfer
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700">
                                                        Manual
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (!empty($conversion['stripe_customer_balance_transaction_id'])): ?>
                                                    <div class="text-xs font-mono mt-1 text-gray-400">
                                                        <?= htmlspecialchars($conversion['stripe_customer_balance_transaction_id']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-gray-400">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-sm font-medium sm:pr-6">
                                            <div class="flex items-center justify-end space-x-2">
                                                <?php if ($conversion['status'] === 'pending'): ?>
                                                    <!-- Mark as Payable -->
                                                    <form method="POST" action="/admin/conversions/update-status" class="inline-block">
                                                        <input type="hidden" name="id" value="<?= $conversion['id'] ?>">
                                                        <input type="hidden" name="status" value="payable">
                                                        <button type="submit"
                                                            class="inline-flex items-center rounded-md bg-white px-2.5 py-1.5 text-sm font-semibold text-green-600 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-green-50">
                                                            <svg class="mr-1.5 h-4 w-4 text-green-500" viewBox="0 0 20 20" fill="currentColor">
                                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                                            </svg>
                                                            Mark Payable
                                                        </button>
                                                    </form>

                                                    <!-- Reject -->
                                                    <form method="POST" action="/admin/conversions/update-status" class="inline-block">
                                                        <input type="hidden" name="id" value="<?= $conversion['id'] ?>">
                                                        <input type="hidden" name="status" value="rejected">
                                                        <button type="submit"
                                                            class="inline-flex items-center rounded-md bg-white px-2.5 py-1.5 text-sm font-semibold text-red-600 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-red-50">
                                                            <svg class="mr-1.5 h-4 w-4 text-red-500" viewBox="0 0 20 20" fill="currentColor">
                                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                                                            </svg>
                                                            Reject
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($conversion['status'] === 'payable'): ?>
                                                    <button
                                                        type="button"
                                                        class="open-payout-modal inline-flex items-center rounded-md bg-white px-2.5 py-1.5 text-sm font-semibold text-blue-600 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-blue-50"
                                                        data-conversion-id="<?= (int) $conversion['id'] ?>"
                                                        data-has-stripe="<?= !empty($conversion['stripe_customer_id']) ? '1' : '0' ?>"
                                                        data-partner-name="<?= htmlspecialchars($conversion['partner_name'] ?? '', ENT_QUOTES) ?>"
                                                        data-commission="<?= number_format((float) ($conversion['commission_amount'] ?? 0), 2, '.', '') ?>">
                                                        <svg class="mr-1.5 h-4 w-4 text-blue-500" viewBox="0 0 20 20" fill="currentColor">
                                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v3.586L7.707 9.293a1 1 0 00-1.414 1.414l3 3a1 1 0 001.414 0l3-3a1 1 0 00-1.414-1.414L11 10.586V7z" clip-rule="evenodd" />
                                                        </svg>
                                                        Review Payout
                                                    </button>
                                                <?php endif; ?>

                                                <!-- View Details button (if needed in the future) -->
                                                <!-- <a href="#" class="inline-flex items-center rounded-md bg-white px-2.5 py-1.5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                                                    <svg class="mr-1.5 h-4 w-4 text-gray-500" viewBox="0 0 20 20" fill="currentColor">
                                                        <path d="M10 12a2 2 0 100-4 2 2 0 000 4z" />
                                                        <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd" />
                                                    </svg>
                                                    View Details
                                                </a> -->
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="adminPayoutModal" class="fixed inset-0 z-50 hidden">
    <div id="adminPayoutBackdrop" class="absolute inset-0 bg-gray-900/60"></div>
    <div class="absolute inset-0 flex items-center justify-center px-4">
        <div class="w-full max-w-lg rounded-lg bg-white shadow-xl">
            <div class="px-6 py-5">
                <h3 class="text-lg font-semibold text-gray-900">Confirm Payout</h3>
                <p class="mt-2 text-sm text-gray-600">
                    You are paying out
                    <span id="adminPayoutAmount" class="font-semibold text-gray-900">$0.00</span>
                    for partner
                    <span id="adminPayoutPartner" class="font-semibold text-gray-900">-</span>.
                </p>
                <div class="mt-4">
                    <label for="adminPayoutSource" class="block text-sm font-medium text-gray-700">Payout Method</label>
                    <select id="adminPayoutSource" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        <?php if ($adminStripeEnabled): ?>
                            <option value="stripe_customer_balance">Stripe Customer Balance</option>
                        <?php endif; ?>
                        <option value="manual">Manual (mark as paid only)</option>
                    </select>
                </div>
                <p class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">
                    Process payouts only after explicit partner request or approval.
                </p>
            </div>
            <div class="flex justify-end gap-3 border-t border-gray-200 px-6 py-4">
                <button type="button" id="adminPayoutCancel" class="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="button" id="adminPayoutSubmit" class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                    Confirm Payout
                </button>
            </div>
        </div>
    </div>
</div>

<form method="POST" action="/admin/conversions/update-status" id="adminPayoutForm" class="hidden">
    <input type="hidden" name="id" id="adminPayoutConversionId" value="">
    <input type="hidden" name="status" value="paid">
    <input type="hidden" name="payout_source" id="adminPayoutSourceInput" value="manual">
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('adminPayoutModal');
    const backdrop = document.getElementById('adminPayoutBackdrop');
    const cancelBtn = document.getElementById('adminPayoutCancel');
    const submitBtn = document.getElementById('adminPayoutSubmit');
    const sourceSelect = document.getElementById('adminPayoutSource');
    const sourceInput = document.getElementById('adminPayoutSourceInput');
    const conversionInput = document.getElementById('adminPayoutConversionId');
    const payoutForm = document.getElementById('adminPayoutForm');
    const partnerLabel = document.getElementById('adminPayoutPartner');
    const amountLabel = document.getElementById('adminPayoutAmount');
    const triggerButtons = document.querySelectorAll('.open-payout-modal');

    if (!modal || !backdrop || !cancelBtn || !submitBtn || !sourceSelect || !sourceInput || !conversionInput || !payoutForm || !partnerLabel || !amountLabel) {
        return;
    }

    const closeModal = function () {
        modal.classList.add('hidden');
    };

    triggerButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const conversionId = button.getAttribute('data-conversion-id') || '';
            const hasStripe = button.getAttribute('data-has-stripe') === '1';
            const partnerName = button.getAttribute('data-partner-name') || '-';
            const commission = button.getAttribute('data-commission') || '0.00';

            conversionInput.value = conversionId;
            partnerLabel.textContent = partnerName;
            amountLabel.textContent = '$' + commission;

            const stripeOption = sourceSelect.querySelector('option[value="stripe_customer_balance"]');
            if (stripeOption) {
                stripeOption.disabled = !hasStripe;
            }

            sourceSelect.value = 'manual';
            if (!sourceSelect.querySelector('option[value="manual"]')) {
                if (stripeOption && !stripeOption.disabled) {
                    sourceSelect.value = 'stripe_customer_balance';
                } else {
                    sourceSelect.selectedIndex = 0;
                }
            }

            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-70', 'cursor-not-allowed');
            sourceInput.value = sourceSelect.value;

            modal.classList.remove('hidden');
        });
    });

    sourceSelect.addEventListener('change', function () {
        sourceInput.value = sourceSelect.value;
    });

    cancelBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', closeModal);

    submitBtn.addEventListener('click', function () {
        sourceInput.value = sourceSelect.value;
        submitBtn.disabled = true;
        submitBtn.classList.add('opacity-70', 'cursor-not-allowed');
        payoutForm.submit();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
            closeModal();
        }
    });
});
</script>
