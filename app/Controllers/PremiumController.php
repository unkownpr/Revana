<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Helpers\InputSanitizer;
use Devana\Services\PremiumStoreService;

final class PremiumController extends Controller
{
    public function store(\Base $f3): void
    {
        $user = $this->requireAuth();
        if ($user === null) return;

        $service = new PremiumStoreService($this->db);
        $data = $service->getStoreData((int) $user['id']);

        $this->render('premium/store.html', [
            'page_title' => $f3->get('lang.premiumStore') ?? 'Premium Store',
            'premium_balance' => (int) ($data['balance'] ?? 0),
            'premium_packages' => $data['packages'] ?? [],
            'premium_products' => $data['products'] ?? [],
        ]);
    }

    public function buyPackage(\Base $f3): void
    {
        if (!$this->requireCsrf('/premium/store')) return;
        $user = $this->requireAuth();
        if ($user === null) return;

        $packageId = InputSanitizer::cleanInt($this->post('package_id', 0));
        if ($packageId <= 0) {
            $this->flashAndRedirect('Invalid package.', '/premium/store');
            return;
        }

        $this->redirect('/premium/checkout/package/' . $packageId);
    }

    public function checkoutPackage(\Base $f3): void
    {
        $user = $this->requireAuth();
        if ($user === null) return;

        $packageId = (int) ($f3->get('PARAMS.id') ?? 0);
        if ($packageId <= 0) {
            $this->flashAndRedirect('Invalid package.', '/premium/store');
            return;
        }

        $service = new PremiumStoreService($this->db);
        $pkg = $service->getActivePackageById($packageId);
        if ($pkg === null) {
            $this->flashAndRedirect($this->translateKey('packageNotFound'), '/premium/store');
            return;
        }

        $this->render('premium/checkout-package.html', [
            'page_title' => $f3->get('lang.checkout') ?? 'Checkout',
            'premium_package' => $pkg,
        ]);
    }

    public function checkoutPackageSubmit(\Base $f3): void
    {
        $packageId = (int) ($f3->get('PARAMS.id') ?? 0);
        if (!$this->requireCsrf('/premium/checkout/package/' . $packageId)) return;
        $user = $this->requireAuth();
        if ($user === null) return;

        if ($packageId <= 0) {
            $this->flashAndRedirect('Invalid package.', '/premium/store');
            return;
        }

        $holder = trim((string) $this->post('card_holder', ''));
        $cardNumberRaw = (string) $this->post('card_number', '');
        $cardNumber = preg_replace('/\D+/', '', $cardNumberRaw) ?? '';
        $expMonth = max(1, min(12, InputSanitizer::cleanInt($this->post('exp_month', 1))));
        $expYear = max((int) date('Y'), InputSanitizer::cleanInt($this->post('exp_year', (int) date('Y'))));
        $cvv = preg_replace('/\D+/', '', (string) $this->post('cvv', '')) ?? '';
        $terms = InputSanitizer::cleanInt($this->post('accept_terms', 0)) === 1;

        if ($holder === '' || strlen($cardNumber) < 13 || strlen($cvv) < 3 || !$terms) {
            $this->flashAndRedirect('Invalid card information.', '/premium/checkout/package/' . $packageId);
            return;
        }

        $last4 = substr($cardNumber, -4);
        $service = new PremiumStoreService($this->db);

        try {
            $service->requestPackagePurchase(
                (int) $user['id'],
                $packageId,
                'card_last4:' . $last4 . ' holder:' . substr($holder, 0, 40) . ' exp:' . sprintf('%02d', $expMonth) . '/' . $expYear
            );
            $this->flashAndRedirect('Card payment request created. It will be reviewed by admin.', '/premium/store');
            return;
        } catch (\RuntimeException $e) {
            $this->flashAndRedirect($this->translateKey($e->getMessage()), '/premium/store');
            return;
        } catch (\Throwable $e) {
            $this->flashAndRedirect('Payment request failed.', '/premium/checkout/package/' . $packageId);
            return;
        }
    }

    public function buyProduct(\Base $f3): void
    {
        if (!$this->requireCsrf('/premium/store')) return;
        $user = $this->requireAuth();
        if ($user === null) return;

        $productId = InputSanitizer::cleanInt($this->post('product_id', 0));
        if ($productId <= 0) {
            $this->flashAndRedirect('Invalid product.', '/premium/store');
            return;
        }

        $service = new PremiumStoreService($this->db);

        try {
            $service->requestProductPurchase((int) $user['id'], $productId);
            $this->flashAndRedirect('Product purchase request created.', '/premium/store');
            return;
        } catch (\RuntimeException $e) {
            $this->flashAndRedirect($this->translateKey($e->getMessage()), '/premium/store');
            return;
        } catch (\Throwable $e) {
            $this->flashAndRedirect('Purchase failed.', '/premium/store');
            return;
        }
    }

    public function inventory(\Base $f3): void
    {
        $user = $this->requireAuth();
        if ($user === null) return;
        $this->redirect('/profile/' . (int) $user['id'] . '?tab=inventory');
    }

    public function activate(\Base $f3): void
    {
        if (!$this->requireCsrf('/premium/inventory')) return;
        $user = $this->requireAuth();
        if ($user === null) return;

        $productId = InputSanitizer::cleanInt($this->post('product_id', 0));
        if ($productId <= 0) {
            $this->flashAndRedirect('Invalid inventory item.', '/premium/inventory');
            return;
        }

        $service = new PremiumStoreService($this->db);

        try {
            $service->activateProduct((int) $user['id'], $productId);
            $this->flashAndRedirect('Cosmetic activated.', '/profile/' . (int) $user['id'] . '?tab=inventory');
            return;
        } catch (\RuntimeException $e) {
            $this->flashAndRedirect($this->translateKey($e->getMessage()), '/profile/' . (int) $user['id'] . '?tab=inventory');
            return;
        } catch (\Throwable $e) {
            $this->flashAndRedirect('Activation failed.', '/profile/' . (int) $user['id'] . '?tab=inventory');
            return;
        }
    }
}
