<?php

namespace Numok\Controllers;

use Numok\Database\Database;
use Numok\Middleware\PartnerMiddleware;


class PayoutController extends Controller
{
    public function __construct()
    {
        PartnerMiddleware::handle();
    }

    public function linkCustomerAccount(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /settings');
            exit;
        }

        $customerEmail = $_POST['customer_email'] ?? null;
        $invoiceNumber = $_POST['invoice_number'] ?? null;

        if (!$customerEmail || !$invoiceNumber) {
            $_SESSION['settings_error'] = 'Customer email and invoice number are required.';
            header('Location: /settings');
            exit;
        }

        // Get Stripe API key
        $stripeApiKey = $this->getStripeApiKey();

        if (empty($stripeApiKey)) {
            $_SESSION['settings_error'] = 'Missing Stripe setup. Contact platform administrator.';
            header('Location: /settings');
            exit;
        }

        // Prepare the query for the invoice search
        $query = "number:'" . $invoiceNumber . "'";

        // Initialize cURL
        $ch = curl_init('https://api.stripe.com/v1/invoices/search');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $stripeApiKey,
                'Stripe-Version: 2023-10-16'
            ],
            CURLOPT_POST => false,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_URL => 'https://api.stripe.com/v1/invoices/search?' . http_build_query(['query' => $query])
        ]);

        // Execute the request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        // Handle the response
        if ($httpCode === 200) {
            $invoiceData = json_decode($response, true);

            if (!empty($invoiceData['data']) && count($invoiceData['data']) > 0) {
                $invoice = $invoiceData['data'][0];

                if (strtolower($invoice['customer_email']) === strtolower($customerEmail)) {
                    // Link the customer account to the partner
                    Database::query(
                        "UPDATE partners SET stripe_customer_id = ? WHERE id = ?",
                        [$invoice['customer'], $_SESSION['partner_id']]
                    );

                    $_SESSION['settings_success'] = 'Customer account linked successfully.';
                    header('Location: /settings');
                    exit;
                } else {
                    $_SESSION['settings_error'] = 'No customer account found for this invoice and email combination.';
                    header('Location: /settings');
                    exit;
                }

                header('Location: /settings');
                exit;
            } else {
                $_SESSION['settings_error'] = 'No invoice found with the provided invoice number.';
                header('Location: /settings');
                exit;
            }
        } elseif ($httpCode === 401) {
            $_SESSION['settings_error'] = 'An error occurred while processing your request. Please try again later.';
            error_log('Stripe API authentication failed. Check your API key.');
            header('Location: /settings');
            exit;
        } else {
            $_SESSION['settings_error'] = 'An error occurred while processing your request. Please try again later.';
            error_log('Stripe API error: ' . $response);
            header('Location: /settings');
            exit;
        }


        $_SESSION['settings_success'] = 'Customer account linked successfully.';
        header('Location: /settings');
        exit;
    }

    public function unlinkCustomerAccount(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /settings');
            exit;
        }

        // Unlink the customer account from the partner
        Database::query(
            "UPDATE partners SET stripe_customer_id = NULL WHERE id = ?",
            [$_SESSION['partner_id']]
        );

        $_SESSION['settings_success'] = 'Customer account unlinked successfully.';
        header('Location: /settings');
        exit;
    }

    private function getStripeApiKey(): string
    {
        $settings = $this->getSettings();
        return $settings['stripe_secret_key'] ?? '';
    }
}
