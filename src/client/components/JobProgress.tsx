import type { ExtensionJobProgress } from '@pterodactyl/sdk';
import { Button } from '@pterodactyl/sdk';

export default function JobProgress({ progress, onCancel }: { progress: ExtensionJobProgress; onCancel?: () => void }) {
    const failed = progress.status === 'failed';

    return (
        <div className='mcc:rounded-sm mcc:bg-card mcc:p-3 mcc:shadow-md' role='status'>
            <div className='mcc:flex mcc:items-center mcc:justify-between mcc:gap-4 mcc:text-sm'>
                <span className={failed ? 'mcc:text-destructive' : 'mcc:text-foreground'}>{progress.message}</span>
                <span className='mcc:flex mcc:items-center mcc:gap-3'>
                    <span className='mcc:font-mono mcc:text-xs mcc:text-muted-foreground'>{progress.percent}%</span>
                    {onCancel && progress.status === 'running' && (
                        <Button.Text size='xsmall' onClick={onCancel}>
                            Cancel
                        </Button.Text>
                    )}
                </span>
            </div>
            <div className='mcc:mt-2 mcc:h-1.5 mcc:overflow-hidden mcc:rounded-full mcc:bg-muted'>
                <div
                    className={`mcc:h-full mcc:rounded-full mcc:transition-[width] mcc:duration-500 ${failed ? 'mcc:bg-destructive' : 'mcc:bg-primary'}`}
                    style={{ width: `${progress.percent}%` }}
                />
            </div>
        </div>
    );
}
