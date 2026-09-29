import WorkspaceController from './WorkspaceController'
import Settings from './Settings'
import VaultController from './VaultController'
import ExistingVaultController from './ExistingVaultController'
import NoteController from './NoteController'
import NoteContentController from './NoteContentController'
import FolderController from './FolderController'
import VaultIndexController from './VaultIndexController'
const Controllers = {
    WorkspaceController: Object.assign(WorkspaceController, WorkspaceController),
Settings: Object.assign(Settings, Settings),
VaultController: Object.assign(VaultController, VaultController),
ExistingVaultController: Object.assign(ExistingVaultController, ExistingVaultController),
NoteController: Object.assign(NoteController, NoteController),
NoteContentController: Object.assign(NoteContentController, NoteContentController),
FolderController: Object.assign(FolderController, FolderController),
VaultIndexController: Object.assign(VaultIndexController, VaultIndexController),
}

export default Controllers