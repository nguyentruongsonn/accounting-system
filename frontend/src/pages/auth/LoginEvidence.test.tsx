import { describe, expect, it } from 'vitest';
import source from './Login.tsx?raw';

describe('login session evidence boundary', () => {
    it('does not claim authentication or persist a malformed 2xx response', () => {
        expect(source).toContain("typeof token !== 'string'");
        expect(source).toContain("token.trim() === ''");
        expect(source).toContain('!user || user.id == null');
        expect(source).toContain('setAuth(user, token)');
        expect(source).toContain('Đăng nhập thất bại hoặc máy chủ chưa trả về phiên hợp lệ.');
    });

    it('presents only the internal accounting-system identity', () => {
        expect(source).toContain('HỆ THỐNG KẾ TOÁN');
        expect(source).toContain('login-brand-mark');
        expect(source).toContain('>A</div>');
        expect(source).not.toContain('NỘI BỘ');
        expect(source).not.toContain('KẾ TOÁN ABC');
    });

    it('does not expose simulated accounts on the login surface', () => {
        expect(source).not.toContain('Tài khoản mô phỏng');
        expect(source).not.toContain('sim.admin@accounting.local');
        expect(source).not.toContain('sim.operator@accounting.local');
        expect(source).not.toContain("initialValues={{ email: 'admin@admin.com'");
    });

    it('exposes semantic autocomplete hints for real browser login flows', () => {
        expect(source).toContain('autoComplete="username"');
        expect(source).toContain('autoComplete="current-password"');
    });

    it('uses the shared toast boundary instead of Ant Design static message calls', () => {
        expect(source).toContain("import { toast } from '../../components/feedback/toast';");
        expect(source).toContain('toast.success');
        expect(source).toContain('toast.error');
        expect(source).not.toContain('message.success');
        expect(source).not.toContain('message.error');
    });
});
