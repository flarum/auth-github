import Modal from 'flarum/components/Modal';
import Button from 'flarum/components/Button';
import saveConfig from 'flarum/utils/saveConfig';

export default class GithubSettingsModal extends Modal {
  constructor(...args) {
    super(...args);

    this.clientId = m.prop(app.config['github.client_id'] || '');
    this.clientSecret = m.prop(app.config['github.client_secret'] || '');
  }

  className() {
    return 'GithubSettingsModal Modal--small';
  }

  title() {
    return 'GitHub Settings';
  }

  content() {
    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label>Client ID</label>
            <input className="FormControl" value={this.clientId()} oninput={m.withAttr('value', this.clientId)}/>
          </div>

          <div className="Form-group">
            <label>Client Secret</label>
            <input className="FormControl" value={this.clientSecret()} oninput={m.withAttr('value', this.clientSecret)}/>
          </div>

          <div className="Form-group">
            <Button
              type="submit"
              className="Button Button--primary GithubSettingsModal-save"
              loading={this.loading}>
              Save Changes
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onsubmit(e) {
    e.preventDefault();

    this.loading = true;

    saveConfig({
      'github.client_id': this.clientId(),
      'github.client_secret': this.clientSecret()
    }).then(
      () => this.hide(),
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }
}
