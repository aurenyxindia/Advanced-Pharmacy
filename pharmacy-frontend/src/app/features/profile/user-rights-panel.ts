import { Component, inject, OnInit, signal, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MetaService } from '../../core/services/meta.service';
import { AuthService } from '../../core/services/auth.service';
import {
  ModuleRight,
  RightsUser,
  UserRightsFormPayload,
  UserRightsService,
} from '../../core/services/user-rights.service';

interface UserForm {
  id: number | null;
  name: string;
  reg_no: string;
  phone: string;
  email: string;
  password: string;
  staff_role: string;
  branch_id: number;
  joining_date: string;
  address: string;
  rights: ModuleRight[];
}

@Component({
  selector: 'app-user-rights-panel',
  imports: [CommonModule, FormsModule],
  templateUrl: './user-rights-panel.html',
  styleUrl: './user-rights-panel.scss',
})
export class UserRightsPanel implements OnInit {
  private readonly rightsSvc = inject(UserRightsService);
  private readonly meta = inject(MetaService);
  private readonly auth = inject(AuthService);

  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly showForm = signal(false);
  readonly message = signal('');
  readonly messageType = signal<'success' | 'error' | ''>('');
  readonly search = signal('');
  readonly users = signal<RightsUser[]>([]);
  readonly modules = signal<ModuleRight[]>([]);
  readonly form = signal<UserForm>(this.emptyForm());

  readonly branches = this.meta.branches;

  readonly filteredUsers = computed(() => {
    const term = this.search().trim().toLowerCase();
    const list = this.users();
    if (!term) return list;
    return list.filter((u) => {
      const blob = [u.name, u.email, u.phone, u.reg_no, u.role]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();
      return blob.includes(term);
    });
  });

  ngOnInit(): void {
    if (!this.meta.branches().length) {
      this.meta.loadBranches().subscribe();
    }
    this.loadModulesThenUsers();
  }

  loadModulesThenUsers(): void {
    this.loading.set(true);
    this.rightsSvc.modules().subscribe({
      next: (mods) => {
        this.modules.set(mods);
        this.loadUsers();
      },
      error: () => {
        this.loading.set(false);
        this.setMessage('Failed to load modules.', 'error');
      },
    });
  }

  loadUsers(): void {
    this.loading.set(true);
    this.rightsSvc.users().subscribe({
      next: (list) => {
        this.users.set(list);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.setMessage('Failed to load users.', 'error');
      },
    });
  }

  openCreate(): void {
    const f = this.emptyForm();
    f.branch_id = this.auth.user()?.branch_id ?? f.branch_id;
    f.rights = this.blankRights();
    this.form.set(f);
    this.showForm.set(true);
    this.message.set('');
  }

  openEdit(user: RightsUser): void {
    this.form.set({
      id: user.id,
      name: user.name ?? '',
      reg_no: user.reg_no ?? '',
      phone: user.phone ?? '',
      email: user.email ?? '',
      password: '',
      staff_role: this.roleToStaff(user.role),
      branch_id: user.branch_id,
      joining_date: user.joining_date ? String(user.joining_date).slice(0, 10) : this.today(),
      address: user.address ?? '',
      rights: this.mergeRights(user.rights ?? []),
    });
    this.showForm.set(true);
    this.message.set('');
  }

  cancelForm(): void {
    this.showForm.set(false);
    this.form.set(this.emptyForm());
  }

  save(): void {
    const f = this.form();
    if (!f.name.trim() || !f.email.trim() || !f.branch_id) {
      this.setMessage('Name, login email and branch are required.', 'error');
      return;
    }
    if (!f.id && f.password.length < 8) {
      this.setMessage('Password must be at least 8 characters for new users.', 'error');
      return;
    }

    const payload: UserRightsFormPayload = {
      id: f.id ?? undefined,
      name: f.name.trim(),
      email: f.email.trim().toLowerCase(),
      phone: f.phone.trim() || undefined,
      reg_no: f.reg_no.trim() || undefined,
      staff_role: f.staff_role,
      branch_id: f.branch_id,
      password: f.password || undefined,
      joining_date: f.joining_date || undefined,
      address: f.address.trim() || undefined,
      rights: f.rights.map((r) => ({
        module_code: r.module_code,
        module_name: r.module_name,
        can_view: r.can_view ? 1 : 0,
        can_edit: r.can_edit ? 1 : 0,
        can_delete: r.can_delete ? 1 : 0,
      })),
    };

    this.saving.set(true);
    this.rightsSvc.saveUser(payload).subscribe({
      next: () => {
        this.saving.set(false);
        this.showForm.set(false);
        this.setMessage('User saved successfully.', 'success');
        this.loadUsers();
      },
      error: (e) => {
        this.saving.set(false);
        const body = e?.error as { message?: string; errors?: Record<string, unknown> } | undefined;
        let msg = '';
        if (body?.errors && typeof body.errors === 'object') {
          msg = Object.values(body.errors).flat().map(String).filter(Boolean).join(' ');
        }
        this.setMessage(msg || body?.message || 'Save failed.', 'error');
      },
    });
  }

  deleteUser(user: RightsUser): void {
    if (!confirm(`Deactivate user "${user.name}"?`)) return;
    this.rightsSvc.deleteUser(user.id).subscribe({
      next: () => {
        this.setMessage('User deactivated.', 'success');
        this.loadUsers();
      },
      error: (e) => this.setMessage(e?.error?.message ?? 'Delete failed.', 'error'),
    });
  }

  onRightChange(row: ModuleRight, field: 'can_view' | 'can_edit' | 'can_delete'): void {
    const checked = !!row[field];
    row[field] = checked ? 1 : 0;
    if ((field === 'can_edit' || field === 'can_delete') && checked) {
      row.can_view = 1;
    }
    if (field === 'can_view' && !checked) {
      row.can_edit = 0;
      row.can_delete = 0;
    }
    this.form.update((f) => ({ ...f, rights: [...f.rights] }));
  }

  toggleColumn(field: 'can_view' | 'can_edit' | 'can_delete', value: boolean): void {
    this.form.update((f) => ({
      ...f,
      rights: f.rights.map((r) => {
        const copy = { ...r, [field]: value ? 1 : 0 };
        if (value && (field === 'can_edit' || field === 'can_delete')) {
          copy.can_view = 1;
        }
        if (!value && field === 'can_view') {
          copy.can_edit = 0;
          copy.can_delete = 0;
        }
        return copy;
      }),
    }));
  }

  roleLabel(role: string): string {
    const map: Record<string, string> = {
      pharmacist: 'Pharmacist',
      purchase: 'Non-Pharmacist',
      cashier: 'Cashier',
      delivery: 'Delivery staff',
      branch_admin: 'Branch Admin',
    };
    return map[role] ?? role;
  }

  rightsSummary(user: RightsUser): string {
    const n = (user.rights ?? []).filter((r) => r.can_view || r.can_edit || r.can_delete).length;
    return n ? `${n} module(s)` : 'No rights';
  }

  patchForm(patch: Partial<UserForm>): void {
    this.form.update((f) => ({ ...f, ...patch }));
  }

  private emptyForm(): UserForm {
    return {
      id: null,
      name: '',
      reg_no: '',
      phone: '',
      email: '',
      password: '',
      staff_role: 'pharmacist',
      branch_id: 0,
      joining_date: this.today(),
      address: '',
      rights: [],
    };
  }

  private today(): string {
    return new Date().toISOString().slice(0, 10);
  }

  private blankRights(): ModuleRight[] {
    return this.modules().map((m) => ({
      module_code: m.module_code,
      module_name: m.module_name,
      can_view: 0,
      can_edit: 0,
      can_delete: 0,
    }));
  }

  private mergeRights(existing: ModuleRight[]): ModuleRight[] {
    const byCode: Record<string, ModuleRight> = {};
    for (const r of existing) {
      let code = (r.module_code || '').toUpperCase();
      if (code === 'DEBIT_NOTE' || code === 'CREDIT_NOTE') code = 'RETURN_NOTE';
      if (!code) continue;
      const prev = byCode[code];
      byCode[code] = {
        ...r,
        module_code: code,
        can_view: r.can_view || prev?.can_view ? 1 : 0,
        can_edit: r.can_edit || prev?.can_edit ? 1 : 0,
        can_delete: r.can_delete || prev?.can_delete ? 1 : 0,
      };
    }
    return this.blankRights().map((m) => {
      const found = byCode[m.module_code.toUpperCase()];
      if (!found) return m;
      return {
        ...m,
        can_view: found.can_view ? 1 : 0,
        can_edit: found.can_edit ? 1 : 0,
        can_delete: found.can_delete ? 1 : 0,
      };
    });
  }

  private roleToStaff(role: string): string {
    if (role === 'purchase') return 'purchase';
    if (role === 'cashier') return 'cashier';
    if (role === 'delivery') return 'delivery';
    return 'pharmacist';
  }

  private setMessage(text: string, type: 'success' | 'error'): void {
    this.message.set(text);
    this.messageType.set(type);
  }
}
