export interface DashboardKpis {
  today_sales: number;
  today_purchase: number;
  month_sales: number;
  month_purchase: number;
  month_profit: number;
  product_count: number;
  low_stock_count: number;
  expiring_count: number;
  expired_count: number;
  customer_count: number;
  branch_count: number;
  outstanding_total: number;
  outstanding_today: number;
}

export interface DistributorPurchase {
  supplier: string;
  total: number;
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

export interface ExpiryLossPeriod {
  batches: number;
  qty: number;
  purchase_value: number;
  sale_value: number;
  expiry_loss: number;
  revenue_loss: number;
  margin_loss: number;
}

export interface ExpiryLoss {
  last_week: ExpiryLossPeriod;
  last_month: ExpiryLossPeriod;
  mtd: ExpiryLossPeriod;
  all_expired: ExpiryLossPeriod;
}

export interface ProfitWindow {
  sales: number;
  profit: number;
  discount_loss: number;
  expected_profit: number;
  missed_profit: number;
}

export interface ProfitBreakdownRow {
  channel?: string;
  category?: string;
  revenue: number;
  profit: number;
  discount_loss: number;
  expected_profit: number;
}

export interface ProfitMonthRow {
  ym: string;
  label: string;
  profit: number;
  discount_loss: number;
  expected_profit: number;
}

export interface ProfitAnalytics {
  month: ProfitWindow;
  three_months: ProfitWindow;
  by_channel: ProfitBreakdownRow[];
  by_product_category: ProfitBreakdownRow[];
  monthly: ProfitMonthRow[];
}

export interface StoreAnalyticsRow {
  branch_id: number;
  code: string;
  name: string;
  expired_batches: number;
  expired_qty: number;
  expiry_loss: number;
  revenue_loss: number;
  margin_loss: number;
  month_profit: number;
  discount_loss: number;
  expected_profit: number;
  missed_profit: number;
  month_sales: number;
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
  purchase_trend: SalesTrendPoint[];
  distributor_purchase: DistributorPurchase[];
  top_products: TopProduct[];
  category_split: CategorySplit[];
  expiry_buckets: ExpiryBuckets;
  expiry_loss?: ExpiryLoss;
  profit_analytics?: ProfitAnalytics;
  store_analytics?: StoreAnalyticsRow[];
  recent_sales: RecentSale[];
  generated_at: string;
  scope: 'ho' | 'branch';
}
