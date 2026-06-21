export interface DashboardKpis {
  today_sales: number;
  today_purchase: number;
  month_sales: number;
  month_profit: number;
  product_count: number;
  low_stock_count: number;
  expiring_count: number;
  customer_count: number;
  branch_count: number;
}

export interface SalesTrendPoint {
  date: string;
  total: number;
}

export interface TopProduct {
  product_name: string;
  qty: number;
  revenue: number;
}

export interface CategorySplit {
  category: string;
  cnt: number;
}

export interface ExpiryBuckets {
  expired: number;
  within_30: number;
  within_90: number;
}

export interface RecentSale {
  invoice_no: string;
  invoice_date: string;
  customer_name: string | null;
  net_amount: number;
}

export interface DashboardSummary {
  kpis: DashboardKpis;
  sales_trend: SalesTrendPoint[];
  top_products: TopProduct[];
  category_split: CategorySplit[];
  expiry_buckets: ExpiryBuckets;
  recent_sales: RecentSale[];
  generated_at: string;
  scope: 'ho' | 'branch';
}
