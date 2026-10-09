import { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Button, Dialog, Input, Label, Select, httpErrorToHuman, toast } from '@pterodactyl/sdk';
import { keys, setPlatform, type Detection } from '../api';
import { useServerIds } from '../hooks';
import { SOFTWARE_NAMES } from '../lib/format';

export default function PlatformDialog({ open, onClose, detection }: { open: boolean; onClose: () => void; detection: Detection }) {
    const { identifier } = useServerIds();
    const queryClient = useQueryClient();
    const [software, setSoftware] = useState(detection.platform.software);
    const [minecraft, setMinecraft] = useState(detection.platform.minecraft ?? '');

    useEffect(() => {
        if (open) {
            setSoftware(detection.platform.software);
            setMinecraft(detection.platform.minecraft ?? '');
        }
    }, [open]);

    const save = useMutation({
        mutationFn: (reset: boolean) => setPlatform(identifier, reset ? null : software, reset || minecraft === '' ? null : minecraft),
        onSuccess: (data) => {
            queryClient.setQueryData(keys.detect(identifier), data);
            queryClient.invalidateQueries({ queryKey: ['minecraft-content', identifier] });
            onClose();
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    return (
        <Dialog open={open} onClose={onClose} title='Server software' description='Content is filtered for this software and Minecraft version. Set it by hand if the detection is wrong.'>
            <div className='mcc:grid mcc:gap-4 mcc:sm:grid-cols-2'>
                <div>
                    <Label htmlFor='mcc-platform-software'>Software</Label>
                    <Select
                        id='mcc-platform-software'
                        value={software}
                        onChange={(value) => setSoftware(String(value))}
                        options={detection.software.map((name) => ({ value: name, label: SOFTWARE_NAMES[name] ?? name }))}
                    />
                </div>
                <div>
                    <Label htmlFor='mcc-platform-version'>Minecraft version</Label>
                    <Input id='mcc-platform-version' placeholder='1.21.1' value={minecraft} onChange={(event) => setMinecraft(event.currentTarget.value.trim())} />
                </div>
            </div>
            <Dialog.Footer>
                {!detection.platform.detected && (
                    <Button.Text className='mcc:w-full mcc:sm:mr-auto mcc:sm:w-auto' disabled={save.isPending} onClick={() => save.mutate(true)}>
                        Detect again
                    </Button.Text>
                )}
                <Button.Text className='mcc:w-full mcc:sm:w-auto' onClick={onClose}>
                    Cancel
                </Button.Text>
                <Button className='mcc:w-full mcc:sm:w-auto' isLoading={save.isPending} disabled={minecraft !== '' && !/^\d+\.\d+(\.\d+)?$/.test(minecraft)} onClick={() => save.mutate(false)}>
                    Save
                </Button>
            </Dialog.Footer>
        </Dialog>
    );
}
