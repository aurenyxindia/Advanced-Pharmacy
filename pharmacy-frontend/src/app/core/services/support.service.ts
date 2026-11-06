import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

export interface SupportFile {
  name: string;
  url: string;
}

export interface SupportTicket {
  id: number;
  ticket_no: string;
  client_id: number;
  client_no: string;
  medical_name: string;
  contact_no: string;
  created_by: string;
  subject: string;
  query_text: string;
  status: string;
  admin_remark: string;
  screenshot_urls: string[];
  admin_screenshot_urls: string[];
  admin_files: SupportFile[];
  reply_count: number;
  created_at: string;
  updated_at: string;
}

export interface SupportReply {
  id: number;
  ticket_id: number;
  sender_name: string;
  is_admin: boolean;
  message: string;
  screenshot_urls: string[];
  files: SupportFile[];
  created_at: string;
}

export interface SupportTicketList {
  is_admin: boolean;
  tickets: SupportTicket[];
  limited_log: boolean;
  log_limit: number | null;
  statuses: string[];
}

export interface SupportConversation {
  ticket: SupportTicket;
  replies: SupportReply[];
  can_reply: boolean;
}

export interface SupportNotification {
  type: 'new_ticket' | 'status_update' | 'ticket_reply';
  key: string;
  ticket: Partial<SupportTicket> & { id: number };
  reply?: SupportReply;
}

export interface SupportPoll {
  is_admin: boolean;
  notifications: SupportNotification[];
  server_time: string;
}

@Injectable({ providedIn: 'root' })
export class SupportService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/support`;

  list(filter: { status?: string; search?: string } = {}): Observable<SupportTicketList> {
    let params = new HttpParams();
    if (filter.status) params = params.set('status', filter.status);
    if (filter.search) params = params.set('search', filter.search);
    return this.http
      .get<ApiResponse<SupportTicketList>>(`${this.base}/tickets`, { params })
      .pipe(map((r) => r.data));
  }

  create(form: FormData): Observable<{ id: number; ticket_no: string; ticket: SupportTicket }> {
    return this.http
      .post<ApiResponse<{ id: number; ticket_no: string; ticket: SupportTicket }>>(`${this.base}/tickets`, form)
      .pipe(map((r) => r.data));
  }

  updateStatus(id: number, form: FormData): Observable<SupportTicket> {
    return this.http
      .post<ApiResponse<{ ticket: SupportTicket }>>(`${this.base}/tickets/${id}/status`, form)
      .pipe(map((r) => r.data.ticket));
  }

  replies(id: number): Observable<SupportConversation> {
    return this.http
      .get<ApiResponse<SupportConversation>>(`${this.base}/tickets/${id}/replies`)
      .pipe(map((r) => r.data));
  }

  postReply(id: number, form: FormData): Observable<{ reply: SupportReply; reopened: boolean; ticket: SupportTicket }> {
    return this.http
      .post<ApiResponse<{ reply: SupportReply; reopened: boolean; ticket: SupportTicket }>>(
        `${this.base}/tickets/${id}/replies`,
        form,
      )
      .pipe(map((r) => r.data));
  }

  poll(since: string): Observable<SupportPoll> {
    const params = since ? new HttpParams().set('since', since) : undefined;
    return this.http.get<ApiResponse<SupportPoll>>(`${this.base}/poll`, { params }).pipe(map((r) => r.data));
  }

  /** `url` is the API-relative path returned with each ticket/reply (e.g. `/support/tickets/5/files/x.png`). */
  fileBlob(url: string): Observable<Blob> {
    return this.http.get(`${environment.apiUrl}${url}`, { responseType: 'blob' });
  }
}
