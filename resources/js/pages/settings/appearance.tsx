import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';

export default function Appearance() {
    return (
        <>
            <Head title="Weergave" />

            <h1 className="sr-only">Weergave</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Weergave"
                    description="Licht of donker, op dit apparaat"
                />
                <AppearanceTabs />
            </div>
        </>
    );
}
