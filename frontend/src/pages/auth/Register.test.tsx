import { describe, expect, it } from 'vitest';
import source from './Register.tsx?raw';

describe('Register API availability contract', () => {
    it('does not call the unregistered self-service signup endpoint', () => {
        expect(source).toContain('Đăng ký tài khoản chưa khả dụng');
        expect(source).toContain('Backend chưa công bố API đăng ký tài khoản');
        expect(source).not.toContain("api.post('/auth/register'");
        expect(source).toMatch(/htmlType="submit"[\s\S]*disabled/);
    });
});
