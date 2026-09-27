import WorkspaceController from './WorkspaceController'
import Settings from './Settings'
import VaultController from './VaultController'
import ExistingVaultController from './ExistingVaultController'
const Controllers = {
    WorkspaceController: Object.assign(WorkspaceController, WorkspaceController),
Settings: Object.assign(Settings, Settings),
VaultController: Object.assign(VaultController, VaultController),
ExistingVaultController: Object.assign(ExistingVaultController, ExistingVaultController),
}

export default Controllers