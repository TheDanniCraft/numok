<div class="py-6">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <h1 class="text-2xl font-semibold text-gray-900">Account Settings</h1>
    </div>

    <div class="mx-auto max-w-7xl px-4 sm:px-6 md:px-8">
        <?php if (isset($_SESSION['settings_success'])): ?>
            <div class="rounded-md bg-green-50 p-4 mt-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-green-800"><?= htmlspecialchars($_SESSION['settings_success']) ?></p>
                    </div>
                </div>
            </div>
        <?php unset($_SESSION['settings_success']);
        endif; ?>

        <?php if (isset($_SESSION['settings_error'])): ?>
            <div class="rounded-md bg-red-50 p-4 mt-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-red-800"><?= htmlspecialchars($_SESSION['settings_error']) ?></p>
                    </div>
                </div>
            </div>
        <?php unset($_SESSION['settings_error']);
        endif; ?>

        <!-- Profile Settings Section -->
        <div class="mt-6">
            <h2 class="text-xl font-semibold text-gray-900">Profile Settings</h2>

            <div class="mt-4 grid grid-cols-1 gap-x-8 gap-y-8 md:grid-cols-3">
                <div class="md:col-span-2">
                    <div class="bg-white shadow sm:rounded-lg">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold leading-6 text-gray-900">Profile Settings</h3>
                            <div class="mt-4 max-w-xl">
                                <form action="/settings/update" method="POST">
                                    <!-- Email -->
                                    <div class="mb-4">
                                        <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                                        <input type="email" name="email" id="email"
                                            value="<?= htmlspecialchars($partner['email']) ?>"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6 px-3">
                                        <p class="mt-1 text-sm text-gray-500">
                                            This email will be used for logging in and account notifications.
                                        </p>
                                    </div>

                                    <!-- Current Password -->
                                    <div class="mb-4">
                                        <label for="current_password" class="block text-sm font-medium text-gray-700">Current Password</label>
                                        <input type="password" name="current_password" id="current_password" required
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6 px-3">
                                    </div>

                                    <!-- New Password -->
                                    <div class="mb-4">
                                        <label for="new_password" class="block text-sm font-medium text-gray-700">New Password</label>
                                        <input type="password" name="new_password" id="new_password" minlength="8"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6 px-3">
                                        <p class="mt-1 text-sm text-gray-500">
                                            Leave blank to keep current password. Must be at least 8 characters.
                                        </p>
                                    </div>

                                    <!-- Confirm New Password -->
                                    <div class="mb-4">
                                        <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm New Password</label>
                                        <input type="password" name="confirm_password" id="confirm_password"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6 px-3">
                                    </div>

                                    <div class="mt-6">
                                        <button type="submit"
                                            class="inline-flex justify-center rounded-md bg-indigo-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                                            Update Profile
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Help Sidebar -->
                <div class="md:col-span-1">
                    <div class="sticky top-6">
                        <div class="rounded-lg bg-white shadow">
                            <div class="px-4 py-5 sm:p-6">
                                <h4 class="text-sm font-medium text-gray-900">Account Security</h4>
                                <div class="mt-4 text-sm text-gray-500 space-y-3">
                                    <p>Keep your account secure:</p>
                                    <ul class="list-disc pl-5 space-y-2">
                                        <li>Use a strong, unique password</li>
                                        <li>Never share your login credentials</li>
                                        <li>Keep your payment email up to date</li>
                                        <li>Review your account activity regularly</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payout Settings Section -->
        <div class="mt-10">
            <h2 class="text-xl font-semibold text-gray-900">Payout Settings</h2>

            <div class="mt-4 grid grid-cols-1 gap-x-8 gap-y-8 md:grid-cols-3">
                <!-- Payout Settings -->
                <div class="md:col-span-2">
                    <div class="bg-white shadow sm:rounded-lg">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold leading-6 text-gray-900">Connect Customer Account</h3>
                            <p class="mt-1 text-sm text-gray-500">
                                To link your customer account, enter the email address associated with your customer account and the invoice number from one of your purchases. This will allow you to payout to your account balance.
                            </p>

                            <div class="mt-4 max-w-xl">
                                <?php if (isset($partner['stripe_customer_id']) && !empty($partner['stripe_customer_id'])): ?>
                                    <p class="text-sm text-gray-700">
                                        Your customer account is linked. To unlink, click the button below.
                                    </p>
                                    <div class="mt-6">
                                        <form action="/payout/unlink" method="POST">
                                            <button
                                                type="submit"
                                                class="inline-flex justify-center rounded-md bg-red-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                                                Unlink Account
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <form action="/payout/link" method="POST">
                                        <!-- Customer Email -->
                                        <div class="mb-4">
                                            <label for="customer_email" class="block text-sm font-medium text-gray-700">Customer Email</label>
                                            <input
                                                type="email"
                                                name="customer_email"
                                                id="customer_email"
                                                required
                                                placeholder="customer@example.com"
                                                class="mt-1 block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 
                                                    ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 
                                                    focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6">
                                            <p class="mt-1 text-sm text-gray-500">
                                                Provide the email address linked to your customer account.
                                            </p>
                                        </div>

                                        <!-- Invoice ID / Number -->
                                        <div class="mb-4">
                                            <label for="invoice_number" class="block text-sm font-medium text-gray-700">Invoice ID</label>
                                            <input
                                                type="text"
                                                name="invoice_number"
                                                id="invoice_number"
                                                required
                                                placeholder="e.g., INV12345"
                                                class="mt-1 block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 
                                                    ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 
                                                    focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6">
                                            <p class="mt-1 text-sm text-gray-500">
                                                Enter the invoice ID from your successful payment.
                                            </p>
                                        </div>

                                        <div class="mt-6">
                                            <button
                                                type="submit"
                                                class="inline-flex justify-center rounded-md bg-indigo-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                                                Link Account
                                            </button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Help Payout Sidebar -->
                <div class="md:col-span-1">
                    <div class="sticky top-6">
                        <div class="rounded-lg bg-white shadow">
                            <div class="px-4 py-5 sm:p-6">
                                <h4 class="text-sm font-medium text-gray-900">Payout Options</h4>
                                <div class="mt-4 text-sm text-gray-500 space-y-3">
                                    <p>You can payout in two ways:</p>
                                    <ul class="list-disc pl-5 space-y-2">
                                        <li>
                                            <strong>Payout to Account Balance:</strong>
                                            Add a credit to your account balance. Future payments will be automatically deducted from this balance.
                                        </li>
                                        <li>
                                            <strong>Payout via Tremendous:</strong>
                                            Receive your payout through Tremendous, including options like gift cards, PayPal, bank transfer, and more depending on your available catalog.
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
