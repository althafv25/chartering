export interface PaginationMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
  from: number | null;
  to: number | null;
}

export interface ApiEnvelope<T> {
  success: true;
  message: string;
  data: T;
}

export interface Paginated<T> extends ApiEnvelope<T[]> {
  meta: PaginationMeta;
}

export interface ApiErrorBody {
  success: false;
  message: string;
  error_code: string;
  errors?: Record<string, string[]>;
  request_id?: string;
}

export interface ListParams {
  page?: number;
  per_page?: number;
  search?: string;
  sort?: string;
  [key: string]: string | number | boolean | undefined;
}
