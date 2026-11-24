<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $admin = Auth::guard('admin')->user();

        $metrics = [
            'total_sales' => (float) Order::where('payment_status', 'paid')->sum('grand_total'),
            'today_sales' => (float) Order::where('payment_status', 'paid')->whereDate('paid_at', today())->sum('grand_total'),
            'month_sales' => (float) Order::where('payment_status', 'paid')
                ->whereMonth('paid_at', now()->month)
                ->whereYear('paid_at', now()->year)
                ->sum('grand_total'),
            'total_orders' => Order::count(),
            'new_orders' => Order::where('order_status', 'pending')->count(),
            'awaiting_payment' => Order::whereIn('payment_status', ['unpaid', 'pending'])->count(),
            'processing_orders' => Order::where('order_status', 'processing')->count(),
            'shipped_orders' => Order::where('order_status', 'shipped')->count(),
            'completed_orders' => Order::where('order_status', 'completed')->count(),
        ];

        $lowStockProducts = Product::whereColumn('stock', '<=', 'min_stock')
            ->where('is_active', true)
            ->orderBy('stock')
            ->limit(5)
            ->get();

        $recentOrders = Order::with('latestPayment')
            ->latest()
            ->limit(5)
            ->get();

        $recentActivities = ActivityLog::latest('created_at')->limit(8)->get();

        return view('admin.dashboard', compact(
            'admin',
            'metrics',
            'lowStockProducts',
            'recentOrders',
            'recentActivities'
        ));
    }
}
