import { Eye, EyeOff } from 'lucide-react';
import type { ComponentProps, Ref } from 'react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';

/**
 * A password field with the eye that turns it back into readable text.
 *
 * The eye sits in the tab order like any other button. It used to carry
 * `tabIndex={-1}` to keep it out of the way, which left the only means of
 * checking a typo behind a mouse — and the reader who most needs to check a
 * typo is the one who cannot see the field at all.
 */
export default function PasswordInput({
    className,
    ref,
    ...props
}: Omit<ComponentProps<'input'>, 'type'> & { ref?: Ref<HTMLInputElement> }) {
    const [showPassword, setShowPassword] = useState(false);
    const { t } = useTranslate();

    return (
        <div className="relative">
            <Input
                type={showPassword ? 'text' : 'password'}
                className={cn('pr-10', className)}
                ref={ref}
                {...props}
            />
            <button
                type="button"
                onClick={() => setShowPassword((prev) => !prev)}
                className="absolute inset-y-0 right-0 flex items-center rounded-r-md px-3 text-muted-foreground hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                aria-label={t(
                    showPassword
                        ? 'auth_screens.fields.password_hide'
                        : 'auth_screens.fields.password_show',
                )}
            >
                {showPassword ? (
                    <EyeOff className="size-4" />
                ) : (
                    <Eye className="size-4" />
                )}
            </button>
        </div>
    );
}
