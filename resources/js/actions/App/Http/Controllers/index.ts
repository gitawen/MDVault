import WorkspaceController from './WorkspaceController'
import Settings from './Settings'
import VaultController from './VaultController'
import ExistingVaultController from './ExistingVaultController'
import EncryptedVaultController from './EncryptedVaultController'
import VaultLockController from './VaultLockController'
import VaultUnlockController from './VaultUnlockController'
import VaultEncryptionController from './VaultEncryptionController'
import VaultPasswordController from './VaultPasswordController'
import NoteController from './NoteController'
import NoteContentController from './NoteContentController'
import NoteDiskController from './NoteDiskController'
import NoteCopyController from './NoteCopyController'
import FolderController from './FolderController'
import VaultIndexController from './VaultIndexController'
import VaultChangeController from './VaultChangeController'
const Controllers = {
    WorkspaceController: Object.assign(WorkspaceController, WorkspaceController),
Settings: Object.assign(Settings, Settings),
VaultController: Object.assign(VaultController, VaultController),
ExistingVaultController: Object.assign(ExistingVaultController, ExistingVaultController),
EncryptedVaultController: Object.assign(EncryptedVaultController, EncryptedVaultController),
VaultLockController: Object.assign(VaultLockController, VaultLockController),
VaultUnlockController: Object.assign(VaultUnlockController, VaultUnlockController),
VaultEncryptionController: Object.assign(VaultEncryptionController, VaultEncryptionController),
VaultPasswordController: Object.assign(VaultPasswordController, VaultPasswordController),
NoteController: Object.assign(NoteController, NoteController),
NoteContentController: Object.assign(NoteContentController, NoteContentController),
NoteDiskController: Object.assign(NoteDiskController, NoteDiskController),
NoteCopyController: Object.assign(NoteCopyController, NoteCopyController),
FolderController: Object.assign(FolderController, FolderController),
VaultIndexController: Object.assign(VaultIndexController, VaultIndexController),
VaultChangeController: Object.assign(VaultChangeController, VaultChangeController),
}

export default Controllers